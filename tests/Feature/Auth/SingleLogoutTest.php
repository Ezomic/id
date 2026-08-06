<?php

use App\Models\Application;
use App\Models\AuthorizedClient;
use App\Models\LogoutNotification;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Token;

function authorizedApp(User $user, string $slug = 'zero'): Application
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

    $verifier = Str::random(64);
    $challenge = strtr(rtrim(base64_encode(hash('sha256', $verifier, true)), '='), '+/', '-_');

    $response = test()->get('/oauth/authorize?'.http_build_query([
        'client_id' => $client->getKey(),
        'redirect_uri' => "https://{$slug}.test/auth/sso/callback",
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
        'redirect_uri' => "https://{$slug}.test/auth/sso/callback",
        'code_verifier' => $verifier,
        'code' => $query['code'],
    ])->assertOk();

    return $application;
}

beforeEach(function () {
    Notification::fake();
});

it('records which clients a session authorized', function () {
    Http::fake();
    $user = User::factory()->create();
    $this->actingAs($user);

    $application = authorizedApp($user);

    expect(AuthorizedClient::where('user_id', $user->id)->where('oauth_client_id', $application->oauth_client_id)->exists())
        ->toBeTrue();
});

it('does not record a client when authorization fails', function () {
    Http::fake();
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->get('/oauth/authorize?'.http_build_query([
        'client_id' => Str::uuid()->toString(),
        'redirect_uri' => 'https://zero.test/auth/sso/callback',
        'response_type' => 'code',
        'scope' => '',
    ]));

    expect(AuthorizedClient::count())->toBe(0);
});

it('notifies every authorized consumer on logout', function () {
    Http::fake();
    $user = User::factory()->create();
    $this->actingAs($user);

    authorizedApp($user, 'zero');
    authorizedApp($user, 'billr');

    $this->post(route('logout'))->assertRedirect();

    expect(LogoutNotification::count())->toBe(2);

    Http::assertSent(fn ($request) => $request->url() === 'https://zero.test/auth/sso/logout');
    Http::assertSent(fn ($request) => $request->url() === 'https://billr.test/auth/sso/logout');
});

it('signs the notification with the application logout secret', function () {
    Http::fake();
    $user = User::factory()->create();
    $this->actingAs($user);

    $application = authorizedApp($user);

    $this->post(route('logout'));

    Http::assertSent(function ($request) use ($application, $user) {
        $signature = $request->header('X-Id-Signature')[0] ?? '';
        $expected = hash_hmac('sha256', $request->body(), (string) $application->logout_secret);
        $payload = json_decode($request->body(), true);

        return hash_equals($expected, $signature)
            && $payload['sub'] === (string) $user->id
            && isset($payload['nonce'], $payload['issued_at']);
    });
});

it('revokes tokens on logout so a missed notification is not the only defence', function () {
    Http::fake();
    $user = User::factory()->create();
    $this->actingAs($user);

    authorizedApp($user);
    expect(Token::where('user_id', $user->id)->count())->toBe(1);

    $this->post(route('logout'));

    expect(Token::where('user_id', $user->id)->count())->toBe(0);
});

it('marks a notification delivered when the consumer accepts it', function () {
    Http::fake();
    $user = User::factory()->create();
    $this->actingAs($user);

    authorizedApp($user);

    $this->post(route('logout'));

    expect(LogoutNotification::first()?->delivered_at)->not->toBeNull();
});

it('keeps a failed notification for the scheduled retry', function () {
    Http::fake(['*' => Http::response('nope', 500)]);

    $user = User::factory()->create();
    $this->actingAs($user);

    authorizedApp($user);

    $this->post(route('logout'));

    $notification = LogoutNotification::first();

    expect($notification?->delivered_at)->toBeNull()
        ->and($notification?->attempts)->toBe(1)
        ->and($notification?->last_error)->toContain('500');
});

it('retries undelivered notifications on the schedule', function () {
    Http::fake();
    $user = User::factory()->create();
    $application = Application::create([
        'name' => 'Zero',
        'slug' => 'zero',
        'logout_secret' => Str::random(64),
        'active' => true,
    ]);

    $notification = LogoutNotification::create([
        'user_id' => $user->id,
        'application_id' => $application->id,
        'endpoint' => 'https://zero.test/auth/sso/logout',
        'attempts' => 1,
    ]);

    $this->artisan('id:retry-logout-notifications')->assertSuccessful();

    expect($notification->fresh()?->delivered_at)->not->toBeNull();
});

it('gives up after the attempt ceiling', function () {
    Http::fake();
    $user = User::factory()->create();
    $application = Application::create([
        'name' => 'Zero',
        'slug' => 'zero',
        'logout_secret' => Str::random(64),
        'active' => true,
    ]);

    LogoutNotification::create([
        'user_id' => $user->id,
        'application_id' => $application->id,
        'endpoint' => 'https://zero.test/auth/sso/logout',
        'attempts' => LogoutNotification::MAX_ATTEMPTS,
    ]);

    $this->artisan('id:retry-logout-notifications')->assertSuccessful();

    Http::assertNothingSent();
});

it('leaves another session\'s authorizations alone', function () {
    Http::fake();
    $user = User::factory()->create();
    $this->actingAs($user);

    authorizedApp($user);

    // A second, unrelated ID session for the same user.
    AuthorizedClient::create([
        'user_id' => $user->id,
        'sso_session_id' => 'some-other-session',
        'oauth_client_id' => Str::uuid()->toString(),
    ]);

    $this->post(route('logout'));

    expect(AuthorizedClient::where('sso_session_id', 'some-other-session')->exists())->toBeTrue();
});

it('does nothing for a session that authorized no clients', function () {
    Http::fake();
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('logout'))->assertRedirect();

    expect(LogoutNotification::count())->toBe(0);
    Http::assertNothingSent();
});
