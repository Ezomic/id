<?php

use App\Actions\Settings\ConfirmEmailChange;
use App\Actions\Settings\RequestEmailChange;
use App\Models\Application;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;

it('requires authentication', function () {
    $this->getJson('/api/userinfo')->assertUnauthorized();
});

it('knows whether a user may access an application', function () {
    $app = Application::create(['name' => 'Zero', 'slug' => 'zero']);
    $user = User::factory()->create();

    expect($user->canAccess($app))->toBeFalse();

    $user->applications()->attach($app);

    expect($user->fresh()->canAccess($app))->toBeTrue();
});

function userinfoApp(?array $allowedScopes = null, string $slug = 'zero'): array
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
        'allowed_scopes' => $allowedScopes,
        'active' => true,
    ]);

    return [$application, $client];
}

function userinfoToken(Application $application, Client $client, User $user, string $scope = ''): string
{
    $user->applications()->syncWithoutDetaching([$application->id]);

    $verifier = Str::random(64);
    $challenge = strtr(rtrim(base64_encode(hash('sha256', $verifier, true)), '='), '+/', '-_');

    $response = test()->actingAs($user)->get('/oauth/authorize?'.http_build_query([
        'client_id' => $client->getKey(),
        'redirect_uri' => $client->redirect_uris[0],
        'response_type' => 'code',
        'scope' => $scope,
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
    ]));

    parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);

    $token = test()->post('/oauth/token', [
        'grant_type' => 'authorization_code',
        'client_id' => $client->getKey(),
        'client_secret' => $client->plainSecret,
        'redirect_uri' => $client->redirect_uris[0],
        'code_verifier' => $verifier,
        'code' => $query['code'],
    ])->assertOk()->json();

    app('auth')->forgetGuards();

    // The router keeps each route's controller between requests, and that
    // controller holds the guard that was just forgotten. Without this a second
    // authorize call in the same test still sees the first user.
    foreach (app('router')->getRoutes() as $route) {
        $route->flushController();
    }

    return $token['access_token'];
}

it('reports a verified email as email_verified true', function () {
    [$application, $client] = userinfoApp();
    $user = User::factory()->create();

    $this->withToken(userinfoToken($application, $client, $user))->getJson('/api/userinfo')
        ->assertOk()
        ->assertJsonPath('email_verified', true);
});

it('reports an unverified email as email_verified false', function () {
    [$application, $client] = userinfoApp();
    $user = User::factory()->unverified()->create();

    $this->withToken(userinfoToken($application, $client, $user))->getJson('/api/userinfo')
        ->assertOk()
        ->assertJsonPath('email_verified', false);
});

it('reports email_verified for any verification timestamp that is set', function (?CarbonImmutable $verifiedAt, bool $expected) {
    [$application, $client] = userinfoApp();
    $user = User::factory()->create(['email_verified_at' => $verifiedAt]);

    $body = $this->withToken(userinfoToken($application, $client, $user))->getJson('/api/userinfo')
        ->assertOk()
        ->json();

    expect($body['email_verified'])->toBe($expected);
})->with([
    'never verified' => [null, false],
    'verified just now' => [fn () => CarbonImmutable::now(), true],
    'verified long ago' => [fn () => CarbonImmutable::parse('2001-01-01'), true],
]);

it('sends email_verified to a scoped application with and without the estate scope', function (string $scope, bool $hasEstate) {
    [$application, $client] = userinfoApp(['identity', 'estate']);
    $user = User::factory()->unverified()->create();

    $body = $this->withToken(userinfoToken($application, $client, $user, $scope))->getJson('/api/userinfo')
        ->assertOk()
        ->assertJsonPath('email_verified', false)
        ->json();

    expect(array_key_exists('applications', $body))->toBe($hasEstate);
})->with([
    'identity only' => ['identity', false],
    'identity and estate' => ['identity estate', true],
]);

it('sends email_verified to an application registered before scoping', function () {
    [$application, $client] = userinfoApp(null);
    $user = User::factory()->create();

    $this->withToken(userinfoToken($application, $client, $user))->getJson('/api/userinfo')
        ->assertOk()
        ->assertJsonPath('email_verified', true);
});

it('never returns another user\'s verification state', function () {
    [$application, $client] = userinfoApp();
    $verified = User::factory()->create();
    $unverified = User::factory()->unverified()->create();

    $verifiedToken = userinfoToken($application, $client, $verified);
    $unverifiedToken = userinfoToken($application, $client, $unverified);

    $this->withToken($unverifiedToken)->getJson('/api/userinfo')
        ->assertOk()
        ->assertJsonPath('sub', (string) $unverified->id)
        ->assertJsonPath('email_verified', false);

    app('auth')->forgetGuards();

    $this->withToken($verifiedToken)->getJson('/api/userinfo')
        ->assertOk()
        ->assertJsonPath('sub', (string) $verified->id)
        ->assertJsonPath('email_verified', true);
});

it('keeps reporting the confirmed address as verified while a change is pending', function () {
    [$application, $client] = userinfoApp();
    $user = User::factory()->create(['email' => 'old@example.test']);

    Notification::fake();
    app(RequestEmailChange::class)->handle($user, 'new@example.test');

    $this->withToken(userinfoToken($application, $client, $user->fresh()))->getJson('/api/userinfo')
        ->assertOk()
        ->assertJsonPath('email', 'old@example.test')
        ->assertJsonPath('email_verified', true);
});

it('reports a confirmed email change as verified', function () {
    [$application, $client] = userinfoApp();
    $user = User::factory()->unverified()->create(['email' => 'old@example.test']);

    $user->forceFill([
        'pending_email' => 'new@example.test',
        'pending_email_token' => Hash::make('known-token'),
        'pending_email_expires_at' => now()->addMinutes(RequestEmailChange::EXPIRY_MINUTES),
    ])->save();

    expect(app(ConfirmEmailChange::class)->handle($user->fresh(), 'known-token'))->toBeTrue();

    $this->withToken(userinfoToken($application, $client, $user->fresh()))->getJson('/api/userinfo')
        ->assertOk()
        ->assertJsonPath('email', 'new@example.test')
        ->assertJsonPath('email_verified', true);
});

it('still refuses an inactive application and a user without access', function () {
    [$application, $client] = userinfoApp();
    $user = User::factory()->create();
    $token = userinfoToken($application, $client, $user);

    $application->forceFill(['active' => false])->save();
    $this->withToken($token)->getJson('/api/userinfo')->assertForbidden()->assertJsonMissingPath('email_verified');

    app('auth')->forgetGuards();
    $application->forceFill(['active' => true])->save();
    $user->applications()->detach($application->id);
    $this->withToken($token)->getJson('/api/userinfo')->assertForbidden()->assertJsonMissingPath('email_verified');
});
