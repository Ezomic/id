<?php

use App\Models\Application;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Passport\ClientRepository;

function registerClient(): array
{
    $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient(
        name: 'Zero',
        redirectUris: ['https://zero.test/auth/sso/callback'],
        confidential: true,
    );

    $application = Application::create([
        'name' => 'Zero',
        'slug' => 'zero',
        'oauth_client_id' => $client->getKey(),
        'active' => true,
    ]);

    return [$client, $application];
}

function authorizationCode(User $user, string $clientId, string $challenge): string
{
    $response = test()->actingAs($user)->get('/oauth/authorize?'.http_build_query([
        'client_id' => $clientId,
        'redirect_uri' => 'https://zero.test/auth/sso/callback',
        'response_type' => 'code',
        'scope' => '',
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
    ]));

    $response->assertRedirect();
    parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);

    return $query['code'];
}

it('runs the full authorization_code + PKCE flow and returns userinfo', function () {
    [$client, $application] = registerClient();

    $user = User::factory()->create();
    $user->applications()->attach($application);

    $verifier = Str::random(64);
    $challenge = strtr(rtrim(base64_encode(hash('sha256', $verifier, true)), '='), '+/', '-_');

    $code = authorizationCode($user, $client->getKey(), $challenge);

    $token = $this->post('/oauth/token', [
        'grant_type' => 'authorization_code',
        'client_id' => $client->getKey(),
        'client_secret' => $client->plainSecret,
        'redirect_uri' => 'https://zero.test/auth/sso/callback',
        'code_verifier' => $verifier,
        'code' => $code,
    ])->assertOk()->json();

    $this->withToken($token['access_token'])
        ->getJson('/api/userinfo')
        ->assertOk()
        ->assertJson([
            'sub' => (string) $user->getKey(),
            'email' => $user->email,
            'name' => $user->name,
            'applications' => ['zero'],
        ]);
});

it('denies userinfo when the user lacks access to the requesting app', function () {
    [$client] = registerClient();

    $user = User::factory()->create();

    $verifier = Str::random(64);
    $challenge = strtr(rtrim(base64_encode(hash('sha256', $verifier, true)), '='), '+/', '-_');

    $code = authorizationCode($user, $client->getKey(), $challenge);

    $token = $this->post('/oauth/token', [
        'grant_type' => 'authorization_code',
        'client_id' => $client->getKey(),
        'client_secret' => $client->plainSecret,
        'redirect_uri' => 'https://zero.test/auth/sso/callback',
        'code_verifier' => $verifier,
        'code' => $code,
    ])->assertOk()->json();

    $this->withToken($token['access_token'])
        ->getJson('/api/userinfo')
        ->assertForbidden();
});
