<?php

use App\Models\Application;
use App\Models\AuthorizedClient;
use App\Models\LogoutNotification;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Token;

beforeEach(function () {
    Http::fake();
    confirmSession();
});

/**
 * @return array{0: Application, 1: array<string, mixed>}
 */
function estateApp(User $user, string $slug = 'zero'): array
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

    // /oauth/authorize runs through the web session, which would otherwise
    // restore whoever signed in during a previous call and authorize them
    // instead of this user.
    test()->flushSession();
    confirmSession();

    $verifier = Str::random(64);
    $challenge = strtr(rtrim(base64_encode(hash('sha256', $verifier, true)), '='), '+/', '-_');

    $response = test()->actingAs($user)->get('/oauth/authorize?'.http_build_query([
        'client_id' => $client->getKey(),
        'redirect_uri' => "https://{$slug}.test/auth/sso/callback",
        'response_type' => 'code',
        'scope' => '',
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
    ]));

    parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);

    $token = test()->post('/oauth/token', [
        'grant_type' => 'authorization_code',
        'client_id' => $client->getKey(),
        'client_secret' => $client->plainSecret,
        'redirect_uri' => "https://{$slug}.test/auth/sso/callback",
        'code_verifier' => $verifier,
        'code' => $query['code'],
    ])->assertOk()->json();

    app('auth')->forgetGuards();

    return [$application, $token];
}

/** Mark a consumer as one the ID-69 probe has seen read the event field. */
function estateConfirm(Application $application): Application
{
    $application->forceFill(['typed_events_confirmed_at' => now()])->save();

    return $application;
}

it('lets a consumer sign the user out of the whole estate', function () {
    $user = User::factory()->create();
    [, $token] = estateApp($user, 'zero');
    estateApp($user, 'billr');

    test()->withToken($token['access_token'])
        ->postJson('/api/sso/logout')
        ->assertOk()
        ->assertJson(['status' => 'signed_out']);

    expect(Token::where('user_id', $user->id)->count())->toBe(0);
    Http::assertSent(fn ($request) => $request->url() === 'https://billr.test/auth/sso/logout');
});

it('refuses an unauthenticated estate logout', function () {
    $this->postJson('/api/sso/logout')->assertUnauthorized();
});

it('cannot sign out a user whose token the caller does not hold', function () {
    $user = User::factory()->create();
    $bystander = User::factory()->create();

    [$application, $token] = estateApp($user);

    // The bystander's presence at the same app. Scoping comes from the token
    // itself, so there is no user parameter for a caller to tamper with.
    $bystanderAuthorization = AuthorizedClient::create([
        'user_id' => $bystander->id,
        'sso_session_id' => 'bystander-session',
        'oauth_client_id' => $application->oauth_client_id,
    ]);

    test()->withToken($token['access_token'])->postJson('/api/sso/logout')->assertOk();

    expect(AuthorizedClient::find($bystanderAuthorization->id))->not->toBeNull();
});

it('tells consumers when access is revoked', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();
    [$application] = estateApp($user);

    AuthorizedClient::create([
        'user_id' => $user->id,
        'sso_session_id' => 'a-session',
        'oauth_client_id' => $application->oauth_client_id,
    ]);

    test()->actingAs($admin)
        ->put(route('admin.users.access.update', $user), ['applications' => []])
        ->assertRedirect();

    expect(LogoutNotification::where('event', LogoutNotification::EVENT_ACCESS_REVOKED)->count())->toBe(1);
});

it('tells consumers when the profile changes', function () {
    $user = User::factory()->create();
    [$application] = estateApp($user);
    estateConfirm($application);

    AuthorizedClient::create([
        'user_id' => $user->id,
        'sso_session_id' => 'a-session',
        'oauth_client_id' => $application->oauth_client_id,
    ]);

    test()->actingAs($user)->patch(route('profile.update'), [
        'name' => 'Renamed Person',
        'email' => $user->email,
    ])->assertRedirect();

    $notification = LogoutNotification::where('event', LogoutNotification::EVENT_USER_UPDATED)->first();

    expect($notification)->not->toBeNull()
        ->and($notification->payload['name'])->toBe('Renamed Person');
});

it('sends the event type on the wire', function () {
    $user = User::factory()->create();
    [$application] = estateApp($user);
    estateConfirm($application);

    AuthorizedClient::create([
        'user_id' => $user->id,
        'sso_session_id' => 'a-session',
        'oauth_client_id' => $application->oauth_client_id,
    ]);

    test()->actingAs($user)->patch(route('profile.update'), [
        'name' => 'Renamed Person',
        'email' => $user->email,
    ]);

    Http::assertSent(function ($request) {
        $payload = json_decode($request->body(), true);

        return ($payload['event'] ?? null) === LogoutNotification::EVENT_USER_UPDATED;
    });
});

it('withholds a profile change from a consumer that would sign the user out', function () {
    $user = User::factory()->create();
    [$application] = estateApp($user);

    AuthorizedClient::create([
        'user_id' => $user->id,
        'sso_session_id' => 'a-session',
        'oauth_client_id' => $application->oauth_client_id,
    ]);

    // id-client 0.2 never reads the event and ends the session on anything it
    // accepts, so delivering this would sign the user out for renaming
    // themselves. Unprobed apps are assumed to be that old.
    test()->actingAs($user)->patch(route('profile.update'), [
        'name' => 'Renamed Person',
        'email' => $user->email,
    ])->assertRedirect();

    expect(LogoutNotification::where('event', LogoutNotification::EVENT_USER_UPDATED)->count())->toBe(0);
    Http::assertNothingSent();
});

it('still signs an unconfirmed consumer out', function () {
    $user = User::factory()->create();
    [$application] = estateApp($user);

    AuthorizedClient::create([
        'user_id' => $user->id,
        'sso_session_id' => 'a-session',
        'oauth_client_id' => $application->oauth_client_id,
    ]);

    // Ending the session is what an old client does with any event it accepts,
    // which is exactly right for these two, so the gate must not block them.
    test()->actingAs($user)->post(route('logout'));

    expect(LogoutNotification::where('event', LogoutNotification::EVENT_LOGOUT)->count())->toBe(1);
});

it('still tells an unconfirmed consumer that access was revoked', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();
    [$application] = estateApp($user);

    AuthorizedClient::create([
        'user_id' => $user->id,
        'sso_session_id' => 'a-session',
        'oauth_client_id' => $application->oauth_client_id,
    ]);

    test()->actingAs($admin)
        ->put(route('admin.users.access.update', $user), ['applications' => []])
        ->assertRedirect();

    expect(LogoutNotification::where('event', LogoutNotification::EVENT_ACCESS_REVOKED)->count())->toBe(1);
});

it('delivers a profile change once the consumer is confirmed', function () {
    $user = User::factory()->create();
    [$application] = estateApp($user);

    AuthorizedClient::create([
        'user_id' => $user->id,
        'sso_session_id' => 'a-session',
        'oauth_client_id' => $application->oauth_client_id,
    ]);

    estateConfirm($application);

    test()->actingAs($user)->patch(route('profile.update'), [
        'name' => 'Renamed Person',
        'email' => $user->email,
    ])->assertRedirect();

    expect(LogoutNotification::where('event', LogoutNotification::EVENT_USER_UPDATED)->count())->toBe(1);
});

it('keeps existing logout rows working across the migration', function () {
    $user = User::factory()->create();
    [$application] = estateApp($user);

    // Rows written before events existed default to logout, so nothing in
    // flight is dropped and re-owed.
    $row = LogoutNotification::create([
        'user_id' => $user->id,
        'application_id' => $application->id,
        'endpoint' => 'https://zero.test/auth/sso/logout',
    ]);

    expect($row->fresh()->event)->toBe(LogoutNotification::EVENT_LOGOUT);
});
