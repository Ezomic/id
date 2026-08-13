<?php

use App\Models\Application;
use App\Models\User;
use App\Services\OidcKeys;
use Illuminate\Support\Str;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Validator;

/**
 * @return array{0: Application, 1: Client}
 */
function oidcApp(): array
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

    return [$application, $client];
}

function exchange(Application $application, $client, User $user, string $scope, ?string $nonce = null): array
{
    $user->applications()->syncWithoutDetaching([$application->id]);

    $verifier = Str::random(64);
    $challenge = strtr(rtrim(base64_encode(hash('sha256', $verifier, true)), '='), '+/', '-_');

    $response = test()->actingAs($user)->get('/oauth/authorize?'.http_build_query(array_filter([
        'client_id' => $client->getKey(),
        'redirect_uri' => $client->redirect_uris[0],
        'response_type' => 'code',
        'scope' => $scope,
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
        'nonce' => $nonce,
    ])));

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

    return $token;
}

it('publishes a discovery document', function () {
    $this->getJson('/.well-known/openid-configuration')
        ->assertOk()
        ->assertJsonStructure([
            'issuer', 'authorization_endpoint', 'token_endpoint', 'userinfo_endpoint',
            'jwks_uri', 'scopes_supported', 'id_token_signing_alg_values_supported',
        ])
        ->assertJsonPath('id_token_signing_alg_values_supported', ['RS256']);
});

it('publishes a usable JWKS', function () {
    $this->getJson('/.well-known/jwks.json')
        ->assertOk()
        ->assertJsonPath('keys.0.kty', 'RSA')
        ->assertJsonPath('keys.0.alg', 'RS256')
        ->assertJsonPath('keys.0.kid', app(OidcKeys::class)->keyId());
});

it('issues an id token when openid is requested', function () {
    [$application, $client] = oidcApp();
    $user = User::factory()->create();

    $token = exchange($application, $client, $user, 'openid identity');

    expect($token)->toHaveKey('id_token');

    $parsed = (new Parser(new JoseEncoder))->parse($token['id_token']);

    expect($parsed->claims()->get('sub'))->toBe((string) $user->id)
        ->and($parsed->claims()->get('name'))->toBe($user->name)
        ->and($parsed->claims()->get('email'))->toBe($user->email)
        ->and($parsed->headers()->get('kid'))->toBe(app(OidcKeys::class)->keyId());
});

it('signs the id token with the published key', function () {
    [$application, $client] = oidcApp();
    $user = User::factory()->create();

    $token = exchange($application, $client, $user, 'openid identity');
    $parsed = (new Parser(new JoseEncoder))->parse($token['id_token']);

    // The point of publishing a JWKS is that a client can verify without a
    // shared secret, so the key we publish has to be the key we sign with.
    $verified = (new Validator)->validate(
        $parsed,
        new SignedWith(new Sha256, InMemory::plainText(app(OidcKeys::class)->publicKey())),
    );

    expect($verified)->toBeTrue();
});

it('binds the nonce from the authorize request', function () {
    [$application, $client] = oidcApp();
    $user = User::factory()->create();

    $token = exchange($application, $client, $user, 'openid identity', 'nonce-abc');
    $parsed = (new Parser(new JoseEncoder))->parse($token['id_token']);

    expect($parsed->claims()->get('nonce'))->toBe('nonce-abc');
});

it('does not reuse a nonce on a second exchange', function () {
    [$application, $client] = oidcApp();
    $user = User::factory()->create();

    exchange($application, $client, $user, 'openid identity', 'nonce-abc');

    // A fresh sign-in with no nonce must not inherit the previous one.
    $second = exchange($application, $client, $user, 'openid identity');
    $parsed = (new Parser(new JoseEncoder))->parse($second['id_token']);

    expect($parsed->claims()->has('nonce'))->toBeFalse();
});

it('withholds identity claims when the scope was not granted', function () {
    [$application, $client] = oidcApp();
    $user = User::factory()->create();

    $token = exchange($application, $client, $user, 'openid');
    $parsed = (new Parser(new JoseEncoder))->parse($token['id_token']);

    expect($parsed->claims()->has('name'))->toBeFalse()
        ->and($parsed->claims()->get('sub'))->toBe((string) $user->id);
});

it('leaves a token response alone when openid was not requested', function () {
    [$application, $client] = oidcApp();
    $user = User::factory()->create();

    // The seven consumers request no scopes at all and must not start getting
    // an id_token they did not ask for.
    expect(exchange($application, $client, $user, ''))->not->toHaveKey('id_token');
});
