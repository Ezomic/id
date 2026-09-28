<?php

declare(strict_types=1);

use App\Models\Application;
use App\Models\AuthorizedClient;
use App\Models\Group;
use App\Models\LogoutNotification;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Token;

beforeEach(fn () => confirmSession());

/**
 * An app on id-client that the user holds a direct grant to.
 *
 * @return array{0: Application, 1: Client}
 */
function lostAccessApp(User $user, string $slug = 'relay'): array
{
    $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient(
        name: ucfirst($slug),
        redirectUris: ["https://{$slug}.test/auth/sso/callback"],
        confidential: true,
    );

    $application = Application::create([
        'name' => ucfirst($slug),
        'slug' => $slug,
        'oauth_client_id' => $client->getKey(),
        'logout_secret' => Str::random(64),
        'active' => true,
    ]);

    $user->applications()->syncWithoutDetaching([$application->id]);

    return [$application, $client];
}

/**
 * Signs in at the app the real way, leaving behind the Passport token and the
 * AuthorizedClient row that lost access used to be worked out from.
 */
function signInAtLostAccessApp(User $user, Application $application, Client $client): void
{
    test()->flushSession();
    confirmSession();

    $redirectUri = "https://{$application->slug}.test/auth/sso/callback";
    $verifier = Str::random(64);
    $challenge = strtr(rtrim(base64_encode(hash('sha256', $verifier, true)), '='), '+/', '-_');

    $response = test()->actingAs($user)->get('/oauth/authorize?'.http_build_query([
        'client_id' => $client->getKey(),
        'redirect_uri' => $redirectUri,
        'response_type' => 'code',
        'scope' => '',
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
    ]));

    parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);

    test()->post('/oauth/token', [
        'grant_type' => 'authorization_code',
        'client_id' => $client->getKey(),
        'client_secret' => $client->plainSecret,
        'redirect_uri' => $redirectUri,
        'code_verifier' => $verifier,
        'code' => $query['code'],
    ])->assertOk();

    app('auth')->forgetGuards();
}

/**
 * The access.revoked calls that went out to this app, as the consumer would
 * receive them.
 *
 * @return Collection<int, Request>
 */
function accessRevokedCallsTo(Application $application): Collection
{
    return Http::recorded(fn (Request $request): bool => $request->url() === $application->logoutUrl()
        && (json_decode($request->body(), true)['event'] ?? null) === LogoutNotification::EVENT_ACCESS_REVOKED)
        ->map(fn (array $pair): Request => $pair[0])
        ->values();
}

function expectOneAccessRevoked(Application $application, User $user): void
{
    $calls = accessRevokedCallsTo($application);

    expect($calls)->toHaveCount(1);

    $request = $calls->first();
    $payload = json_decode($request->body(), true);

    expect($payload['sub'])->toBe((string) $user->id)
        ->and($request->header('X-Id-Signature')[0] ?? '')
        ->toBe(hash_hmac('sha256', $request->body(), (string) $application->logout_secret));
}

/**
 * @param  list<int>  $keep
 */
function revokeDirectGrant(User $user, array $keep = []): void
{
    test()->actingAs(User::factory()->admin()->create())
        ->put(route('admin.users.access.update', $user), ['applications' => $keep])
        ->assertSessionHas('status', 'Access updated.');
}

/**
 * @param  array<string, mixed>  $changes
 */
function saveRelayOnApplicationsScreen(Application $relay, array $changes): void
{
    test()->actingAs(User::factory()->admin()->create())
        ->put(route('admin.applications.update', $relay), [
            'name' => 'Relay',
            'slug' => 'relay',
            'redirect_uri' => 'https://relay.test/auth/sso/callback',
            'active' => true,
            ...$changes,
        ])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'Application saved.');
}

it('tells the app when the user has not used it since their token was purged', function () {
    Http::fake();
    $user = User::factory()->create();
    [$relay, $client] = lostAccessApp($user);
    signInAtLostAccessApp($user, $relay, $client);

    // id-client never refreshes, so the 15 minute access token is all there
    // is, and the daily purge removes it a week after it expired.
    $this->travel(8)->days();
    $this->artisan('passport:purge')->assertSuccessful();

    expect(Token::where('user_id', $user->id)->count())->toBe(0);

    revokeDirectGrant($user);

    expectOneAccessRevoked($relay, $user);
});

it('tells the app when the user signed out at ID first', function () {
    Http::fake();
    $user = User::factory()->create();
    [$relay, $client] = lostAccessApp($user);
    signInAtLostAccessApp($user, $relay, $client);

    // Signing out at ID deletes both the tokens and the AuthorizedClient rows
    // for the app, while any API token the app issued to the user lives on.
    test()->actingAs($user)->post(route('logout'))->assertRedirect();
    app('auth')->forgetGuards();
    confirmSession();

    expect(Token::where('user_id', $user->id)->count())->toBe(0)
        ->and(AuthorizedClient::where('user_id', $user->id)->count())->toBe(0);

    revokeDirectGrant($user);

    expectOneAccessRevoked($relay, $user);
});

it('still tells the app and revokes the token when the user holds a live one', function () {
    Http::fake();
    $user = User::factory()->create();
    [$relay, $client] = lostAccessApp($user);
    signInAtLostAccessApp($user, $relay, $client);

    expect(Token::where('user_id', $user->id)->count())->toBe(1);

    revokeDirectGrant($user);

    expectOneAccessRevoked($relay, $user);
    expect(Token::where('user_id', $user->id)->count())->toBe(0);
});

it('tells only the app that was taken away', function () {
    Http::fake();
    $user = User::factory()->create();
    [$relay] = lostAccessApp($user, 'relay');
    [$zero] = lostAccessApp($user, 'zero');

    revokeDirectGrant($user, [$zero->id]);

    expectOneAccessRevoked($relay, $user);
    expect(accessRevokedCallsTo($zero))->toBeEmpty();
});

it('does not tell an app the user can still reach through a group', function () {
    Http::fake();
    $user = User::factory()->create();
    [$relay] = lostAccessApp($user);

    $group = Group::create(['name' => 'Everyone']);
    $group->users()->attach($user);
    $group->applications()->attach($relay);

    revokeDirectGrant($user);

    expect(accessRevokedCallsTo($relay))->toBeEmpty()
        ->and(LogoutNotification::count())->toBe(0);
});

it('tells the app when the user is removed on the applications screen', function () {
    Http::fake();
    $user = User::factory()->create();
    $colleague = User::factory()->create();
    [$relay, $client] = lostAccessApp($user);
    $colleague->applications()->attach($relay);
    signInAtLostAccessApp($user, $relay, $client);

    saveRelayOnApplicationsScreen($relay, ['users' => [$colleague->id]]);

    expectOneAccessRevoked($relay, $user);
    expect(Token::where('user_id', $user->id)->count())->toBe(0)
        ->and(LogoutNotification::where('user_id', $colleague->id)->count())->toBe(0);
});

it('tells the app when the user is removed there without ever having signed in', function () {
    Http::fake();
    $user = User::factory()->create();
    [$relay] = lostAccessApp($user);

    saveRelayOnApplicationsScreen($relay, ['users' => []]);

    expectOneAccessRevoked($relay, $user);
});

it('leaves the access list alone when the applications screen does not send one', function () {
    Http::fake();
    $user = User::factory()->create();
    [$relay] = lostAccessApp($user);

    saveRelayOnApplicationsScreen($relay, []);

    expect($user->canAccess($relay))->toBeTrue()
        ->and(LogoutNotification::count())->toBe(0);
});

/**
 * @return array{0: User, 1: Application, 2: Group}
 */
function groupGrantedRelay(): array
{
    $user = User::factory()->create();
    [$relay] = lostAccessApp($user);

    // Access now comes only from the group.
    $user->applications()->detach();
    $group = Group::create(['name' => 'Everyone']);
    $group->users()->attach($user);
    $group->applications()->attach($relay);

    return [$user, $relay, $group];
}

it('tells the app when a group loses it', function () {
    Http::fake();
    [$user, $relay, $group] = groupGrantedRelay();

    test()->actingAs(User::factory()->admin()->create())
        ->put(route('admin.groups.update', $group), ['users' => [$user->id], 'applications' => []])
        ->assertRedirect();

    expectOneAccessRevoked($relay, $user);
});

it('tells the app when the user leaves the group that granted it', function () {
    Http::fake();
    [$user, $relay, $group] = groupGrantedRelay();

    test()->actingAs(User::factory()->admin()->create())
        ->put(route('admin.groups.update', $group), ['users' => [], 'applications' => [$relay->id]])
        ->assertRedirect();

    expectOneAccessRevoked($relay, $user);
});

it('tells the app when the group that granted it is deleted', function () {
    Http::fake();
    [$user, $relay, $group] = groupGrantedRelay();

    test()->actingAs(User::factory()->admin()->create())
        ->delete(route('admin.groups.destroy', $group))
        ->assertRedirect();

    expectOneAccessRevoked($relay, $user);
});

it('keeps an undelivered access.revoked for the scheduled retry', function () {
    Http::fake(['*' => Http::response('down', 503)]);

    $user = User::factory()->create();
    [$relay] = lostAccessApp($user);

    revokeDirectGrant($user);

    $notification = LogoutNotification::where('event', LogoutNotification::EVENT_ACCESS_REVOKED)->sole();

    expect($notification->application_id)->toBe($relay->id)
        ->and($notification->delivered_at)->toBeNull()
        ->and($notification->attempts)->toBe(1);
});
