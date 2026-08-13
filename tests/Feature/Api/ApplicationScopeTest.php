<?php

use App\Models\Application;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;

/**
 * @return array{0: Application, 1: Client}
 */
function scopedApp(?array $allowedScopes, string $slug = 'zero'): array
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

function tokenWithScope(Application $application, $client, User $user, string $scope): string
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

    return $token['access_token'];
}

it('leaves an application registered before scoping exactly as it was', function () {
    // This is the case that matters: seven live consumers request scope= and
    // expect the full payload. Narrowing them silently would break the estate.
    [$application, $client] = scopedApp(null);
    $user = User::factory()->create();

    $token = tokenWithScope($application, $client, $user, '');

    test()->withToken($token)->getJson('/api/userinfo')
        ->assertOk()
        ->assertJsonStructure(['sub', 'name', 'email', 'applications']);
});

it('withholds the estate list from a scoped app that did not ask', function () {
    [$application, $client] = scopedApp(['identity', 'estate']);
    $user = User::factory()->create();

    $token = tokenWithScope($application, $client, $user, 'identity');

    test()->withToken($token)->getJson('/api/userinfo')
        ->assertOk()
        ->assertJsonMissingPath('applications')
        ->assertJsonPath('sub', (string) $user->id);
});

it('returns the estate list to a scoped app that asked', function () {
    [$application, $client] = scopedApp(['identity', 'estate']);
    $user = User::factory()->create();

    $token = tokenWithScope($application, $client, $user, 'identity estate');

    test()->withToken($token)->getJson('/api/userinfo')
        ->assertOk()
        ->assertJsonStructure(['applications']);
});

it('refuses a scope the application was never allowed', function () {
    [$application, $client] = scopedApp(['identity']);
    $user = User::factory()->create();
    $user->applications()->attach($application);

    test()->actingAs($user)->get('/oauth/authorize?'.http_build_query([
        'client_id' => $client->getKey(),
        'redirect_uri' => $client->redirect_uris[0],
        'response_type' => 'code',
        'scope' => 'estate',
    ]))->assertForbidden();
});

it('does not restrict an unscoped application at authorize time', function () {
    [$application, $client] = scopedApp(null);
    $user = User::factory()->create();

    // Reaching a token at all proves the allowlist did not engage.
    $token = tokenWithScope($application, $client, $user, 'estate');

    expect($token)->not->toBeEmpty();
});

it('knows the difference between unscoped and empty-scoped', function () {
    $unscoped = Application::create(['name' => 'A', 'slug' => 'a', 'active' => true]);
    $locked = Application::create(['name' => 'B', 'slug' => 'b', 'allowed_scopes' => [], 'active' => true]);

    expect($unscoped->allowsScope('estate'))->toBeTrue()
        ->and($unscoped->grantsScope('estate', ''))->toBeTrue()
        // An empty allowlist is a deliberate "nothing", not "not configured".
        ->and($locked->allowsScope('estate'))->toBeFalse()
        ->and($locked->grantsScope('estate', 'estate'))->toBeFalse();
});
