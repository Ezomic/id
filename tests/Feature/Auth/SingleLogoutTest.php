<?php

use App\Listeners\PropagateLogout;
use App\Models\Application;
use App\Models\AuthorizedClient;
use App\Models\LogoutNotification;
use App\Models\User;
use App\Services\SsoSessionId;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\Auth;
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

/**
 * Runs every later request the way a browser and a fresh server process would
 * see it. The test app outlives a request, and so do its session store and
 * cookie queue, so a request would otherwise still see what an earlier one put
 * in the session after it was saved, or queued after its cookies were sent.
 */
function browserSession(): void
{
    app('events')->listen(RequestHandled::class, function (RequestHandled $event): void {
        foreach ($event->response->headers->getCookies() as $cookie) {
            test()->withUnencryptedCookie($cookie->getName(), $cookie->isCleared() ? '' : (string) $cookie->getValue());
        }

        app('cookie')->flushQueuedCookies();
        app('session')->driver()->flush();
    });
}

/**
 * The 120-minute session is gone. The browser still has its other cookies,
 * the remember-me one included.
 */
function sessionIdlesOut(): void
{
    test()->withUnencryptedCookie(app('session')->driver()->getName(), '');
    app('auth')->forgetGuards();
}

function rememberedBrowser(User $user): void
{
    $user->forceFill(['remember_token' => Str::random(60)])->save();
    $guard = Auth::guard('web');

    test()->withCookie(
        $guard->getRecallerName(),
        $user->id.'|'.$user->remember_token.'|'.$guard->hashPasswordForCookie($user->getAuthPassword()),
    );
    test()->actingAs($user);
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

it('propagates one logout exactly once per client', function () {
    Http::fake();
    $user = User::factory()->create();
    $this->actingAs($user);

    $zero = authorizedApp($user, 'zero');
    $billr = authorizedApp($user, 'billr');

    // The dispatcher resolves a class listener from the container on every run.
    $runs = 0;
    $this->app->resolving(PropagateLogout::class, function () use (&$runs): void {
        $runs++;
    });

    $this->post(route('logout'))->assertRedirect();

    expect($runs)->toBe(1)
        ->and(LogoutNotification::where('application_id', $zero->id)->count())->toBe(1)
        ->and(LogoutNotification::where('application_id', $billr->id)->count())->toBe(1);

    Http::assertSentCount(2);
});

it('propagates a sign-out that ends only this browser exactly once per client', function () {
    Http::fake();
    $user = User::factory()->create();
    $this->actingAs($user);
    $rememberToken = $user->remember_token;

    $zero = authorizedApp($user, 'zero');
    $billr = authorizedApp($user, 'billr');

    $runs = 0;
    $this->app->resolving(PropagateLogout::class, function () use (&$runs): void {
        $runs++;
    });

    $this->post(route('logout'))->assertRedirect();

    // An unchanged token is what keeps the user's other browsers signed in.
    expect($user->fresh()?->remember_token)->toBe($rememberToken)
        ->and($runs)->toBe(1)
        ->and(LogoutNotification::where('application_id', $zero->id)->count())->toBe(1)
        ->and(LogoutNotification::where('application_id', $billr->id)->count())->toBe(1);

    Http::assertSentCount(2);
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

it('reaches the apps a session signed in to once that session is read back from storage', function () {
    Http::fake();
    browserSession();
    $user = User::factory()->create();
    $this->actingAs($user);

    authorizedApp($user, 'zero');

    $this->post(route('logout'))->assertRedirect();

    Http::assertSent(fn ($request) => $request->url() === 'https://zero.test/auth/sso/logout');
});

it('reaches the apps a browser signed in to before a remember-me restore', function () {
    Http::fake();
    browserSession();
    $user = User::factory()->create();
    rememberedBrowser($user);

    authorizedApp($user, 'zero');

    sessionIdlesOut();
    $this->post(route('logout'))->assertRedirect();

    Http::assertSent(fn ($request) => $request->url() === 'https://zero.test/auth/sso/logout');
    expect(AuthorizedClient::where('user_id', $user->id)->exists())->toBeFalse();
});

it('reaches the apps from before and after a remember-me restore alike', function () {
    Http::fake();
    browserSession();
    $user = User::factory()->create();
    rememberedBrowser($user);

    authorizedApp($user, 'zero');

    sessionIdlesOut();
    authorizedApp($user, 'billr');

    $this->post(route('logout'))->assertRedirect();

    Http::assertSent(fn ($request) => $request->url() === 'https://zero.test/auth/sso/logout');
    Http::assertSent(fn ($request) => $request->url() === 'https://billr.test/auth/sso/logout');
    expect(AuthorizedClient::where('user_id', $user->id)->exists())->toBeFalse();
});

it('keeps apart the apps two people signed in to from the same browser', function () {
    Http::fake();
    browserSession();
    $first = User::factory()->create();
    $this->actingAs($first);
    $zero = authorizedApp($first, 'zero');

    // The first person's session ends without a sign-out, and the next person
    // signs in on the same browser to the same app.
    sessionIdlesOut();
    $second = User::factory()->create();
    $second->applications()->attach($zero->id);
    $this->actingAs($second);
    $this->get('/oauth/authorize?'.http_build_query([
        'client_id' => $zero->oauth_client_id,
        'redirect_uri' => 'https://zero.test/auth/sso/callback',
        'response_type' => 'code',
        'scope' => '',
    ]))->assertRedirect();

    $this->post(route('logout'))->assertRedirect();

    expect(AuthorizedClient::where('user_id', $first->id)->where('oauth_client_id', $zero->oauth_client_id)->exists())->toBeTrue()
        ->and(LogoutNotification::where('user_id', $second->id)->count())->toBe(1)
        ->and(LogoutNotification::where('user_id', $first->id)->exists())->toBeFalse();
});

it('forgets the browser\'s SSO session when it signs out', function () {
    Http::fake();
    browserSession();
    $user = User::factory()->create();
    $this->actingAs($user);

    authorizedApp($user);

    $this->post(route('logout'))->assertCookieExpired(SsoSessionId::COOKIE);
});
