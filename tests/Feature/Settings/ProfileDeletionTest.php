<?php

declare(strict_types=1);

use App\Models\Application;
use App\Models\AuthorizedClient;
use App\Models\LogoutNotification;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Token;

beforeEach(fn () => confirmSession());

/**
 * An app on id-client the user holds a grant to. With $signIn the user signs
 * in to it from the session that will delete the account, the way a browser
 * does, which records the AuthorizedClient row logout is worked out from.
 */
function deletionApp(User $user, string $slug, bool $signIn = false): Application
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

    if (! $signIn) {
        return $application;
    }

    $redirectUri = "https://{$slug}.test/auth/sso/callback";
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

    return $application;
}

/**
 * The signed calls that reached this app for the given event.
 *
 * @return Collection<int, array<string, mixed>>
 */
function deletionCallsTo(Application $application, string $event): Collection
{
    return Http::recorded(fn (Request $request): bool => $request->url() === $application->logoutUrl())
        ->map(fn (array $pair): Request => $pair[0])
        ->filter(fn (Request $request): bool => hash_equals(
            hash_hmac('sha256', $request->body(), (string) $application->logout_secret),
            $request->header('X-Id-Signature')[0] ?? '',
        ))
        ->map(fn (Request $request): array => json_decode($request->body(), true))
        ->filter(fn (array $payload): bool => $payload['event'] === $event)
        ->values();
}

function deleteOwnAccount(User $user): void
{
    test()->actingAs($user)->delete(route('profile.destroy'))->assertRedirect('/');
}

it('signs the user out of every app they had a session with', function () {
    Http::fake();
    $user = User::factory()->create();
    $zero = deletionApp($user, 'zero', signIn: true);
    $billr = deletionApp($user, 'billr');

    // Signed in to billr from another device, so another ID session.
    AuthorizedClient::create([
        'user_id' => $user->id,
        'sso_session_id' => 'the-phone',
        'oauth_client_id' => $billr->oauth_client_id,
    ]);

    deleteOwnAccount($user);

    expect(User::find($user->id))->toBeNull();

    foreach ([$zero, $billr] as $application) {
        $logouts = deletionCallsTo($application, LogoutNotification::EVENT_LOGOUT);

        expect($logouts)->toHaveCount(1)
            ->and($logouts->first()['sub'])->toBe((string) $user->id);
    }
});

it('tells every app the user could reach that their access is gone', function () {
    Http::fake();
    $user = User::factory()->create();
    $zero = deletionApp($user, 'zero', signIn: true);

    // Never signed in to relay from this browser, but it may still hold API
    // tokens the user minted there.
    $relay = deletionApp($user, 'relay');

    deleteOwnAccount($user);

    foreach ([$zero, $relay] as $application) {
        $revoked = deletionCallsTo($application, LogoutNotification::EVENT_ACCESS_REVOKED);

        expect($revoked)->toHaveCount(1)
            ->and($revoked->first()['sub'])->toBe((string) $user->id);
    }
});

it('revokes every token the account held', function () {
    Http::fake();
    $user = User::factory()->create();
    deletionApp($user, 'zero', signIn: true);

    expect(Token::where('user_id', $user->id)->count())->toBe(1);

    deleteOwnAccount($user);

    expect(Token::where('user_id', $user->id)->count())->toBe(0);
});

it('keeps a failed delivery for the retry after the account is gone', function () {
    $consumerUp = false;
    Http::fake(function () use (&$consumerUp) {
        return $consumerUp ? Http::response('ok') : Http::response('down', 503);
    });

    $user = User::factory()->create();
    $zero = deletionApp($user, 'zero', signIn: true);

    deleteOwnAccount($user);

    $owed = LogoutNotification::query()->where('application_id', $zero->id)->get();

    expect(User::find($user->id))->toBeNull()
        ->and($owed->pluck('event')->sort()->values()->all())
        ->toBe([LogoutNotification::EVENT_ACCESS_REVOKED, LogoutNotification::EVENT_LOGOUT])
        ->and($owed->every(fn (LogoutNotification $n): bool => $n->user_id === $user->id
            && $n->delivered_at === null
            && $n->attempts === 1))->toBeTrue();

    $consumerUp = true;

    $this->artisan('id:retry-logout-notifications')->assertSuccessful();

    expect(LogoutNotification::whereNull('delivered_at')->count())->toBe(0)
        ->and(deletionCallsTo($zero, LogoutNotification::EVENT_LOGOUT)->pluck('sub')->unique()->all())
        ->toBe([(string) $user->id])
        ->and(deletionCallsTo($zero, LogoutNotification::EVENT_ACCESS_REVOKED)->pluck('sub')->unique()->all())
        ->toBe([(string) $user->id]);
});

it('deletes an account that never signed in to any app', function () {
    Http::fake();
    $user = User::factory()->create();

    deleteOwnAccount($user);

    expect(User::find($user->id))->toBeNull()
        ->and(LogoutNotification::count())->toBe(0);
    Http::assertNothingSent();
});
