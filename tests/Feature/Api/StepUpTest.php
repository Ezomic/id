<?php

use App\Actions\Auth\GenerateRecoveryCodes;
use App\Http\Middleware\EnforceMaxAge;
use App\Models\Application;
use App\Models\LogoutNotification;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Token\DataSet;
use Lcobucci\JWT\Token\Parser;

/**
 * @return array{0: Application, 1: Client}
 */
function stepUpApp(string $slug = 'zero', string $redirect = 'https://zero.test/auth/sso/callback'): array
{
    $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient(
        name: ucfirst($slug),
        redirectUris: [$redirect],
        confidential: true,
    );

    $application = Application::create([
        'name' => ucfirst($slug),
        'slug' => $slug,
        'oauth_client_id' => $client->getKey(),
        'active' => true,
    ]);

    return [$application, $client];
}

/**
 * @param  array<string, mixed>  $extra
 * @return array{url: string, verifier: string}
 */
function stepUpRequest(Client $client, array $extra = [], string $scope = 'openid'): array
{
    $verifier = Str::random(64);

    $query = array_merge([
        'client_id' => $client->getKey(),
        'redirect_uri' => $client->redirect_uris[0],
        'response_type' => 'code',
        'scope' => $scope,
        'state' => 'st-123',
        'code_challenge' => strtr(rtrim(base64_encode(hash('sha256', $verifier, true)), '='), '+/', '-_'),
        'code_challenge_method' => 'S256',
    ], $extra);

    return ['url' => '/oauth/authorize?'.http_build_query(array_filter($query, fn ($v) => $v !== null)), 'verifier' => $verifier];
}

/**
 * @return array<string, mixed>
 */
function locationQuery(TestResponse $response): array
{
    parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);

    return $query;
}

/**
 * @return array<string, mixed>
 */
function stepUpExchange(Client $client, string $code, string $verifier): array
{
    $response = test()->post('/oauth/token', [
        'grant_type' => 'authorization_code',
        'client_id' => $client->getKey(),
        'client_secret' => $client->plainSecret,
        'redirect_uri' => $client->redirect_uris[0],
        'code_verifier' => $verifier,
        'code' => $code,
    ])->assertOk();

    return $response->json();
}

function claimsOf(string $idToken): DataSet
{
    return (new Parser(new JoseEncoder))->parse($idToken)->claims();
}

function confirmedUser(?User $user = null, ?int $at = null): User
{
    $user ??= User::factory()->create();
    test()->actingAs($user)->withSession(['auth.password_confirmed_at' => $at ?? Date::now()->unix()]);

    return $user;
}

it('issues a code and an auth_time claim when the confirmation is within max_age', function () {
    [$application, $client] = stepUpApp();
    $at = Date::now()->unix() - 20;
    $user = confirmedUser(at: $at);
    $user->applications()->attach($application);

    $request = stepUpRequest($client, ['max_age' => 60]);
    $response = $this->get($request['url'])->assertRedirectContains('https://zero.test/auth/sso/callback');

    $token = stepUpExchange($client, locationQuery($response)['code'], $request['verifier']);
    $claims = claimsOf($token['id_token']);

    expect($claims->get('auth_time'))->toBe($at)
        ->and($claims->get('sub'))->toBe((string) $user->id);
});

it('sends a stale confirmation to re-authenticate and remembers the authorize request', function () {
    [, $client] = stepUpApp();
    confirmedUser(at: Date::now()->unix() - 120);

    $request = stepUpRequest($client, ['max_age' => 60]);

    $this->get($request['url'])->assertRedirect(route('reauthenticate.show'));

    expect(session('url.intended'))->toBeString();
    parse_str((string) parse_url(session('url.intended'), PHP_URL_QUERY), $intended);
    parse_str((string) parse_url($request['url'], PHP_URL_QUERY), $asked);
    expect($intended)->toEqual($asked)
        ->and(parse_url(session('url.intended'), PHP_URL_PATH))->toBe('/oauth/authorize');
});

it('sends a session that never re-authenticated to re-authenticate', function () {
    [, $client] = stepUpApp();
    $this->actingAs(User::factory()->create());

    $this->get(stepUpRequest($client, ['max_age' => 300])['url'])
        ->assertRedirect(route('reauthenticate.show'));
});

it('treats a confirmation dated in the future as stale', function () {
    [, $client] = stepUpApp();
    confirmedUser(at: Date::now()->unix() + 3600);

    $this->get(stepUpRequest($client, ['max_age' => 300])['url'])
        ->assertRedirect(route('reauthenticate.show'));
});

it('completes the whole step-up through a recovery code without ending the ID session', function () {
    [$application, $client] = stepUpApp();
    $user = User::factory()->create();
    $user->applications()->attach($application);
    $codes = app(GenerateRecoveryCodes::class)->handle($user);
    $this->actingAs($user);

    $request = stepUpRequest($client, ['max_age' => 60]);

    $this->get($request['url'])->assertRedirect(route('reauthenticate.show'));

    $before = Date::now()->unix();
    $back = $this->post(route('reauthenticate.confirm'), ['code' => $codes[0]])
        ->assertRedirectContains('/oauth/authorize?');

    $response = $this->get($back->headers->get('Location'))
        ->assertRedirectContains('https://zero.test/auth/sso/callback');

    $token = stepUpExchange($client, locationQuery($response)['code'], $request['verifier']);
    $authTime = claimsOf($token['id_token'])->get('auth_time');

    expect($authTime)->toBeGreaterThanOrEqual($before)
        ->and($authTime)->toBeLessThanOrEqual(Date::now()->unix());
    $this->assertAuthenticatedAs($user);
    expect(LogoutNotification::count())->toBe(0);
});

it('does not log the user out of ID when a step-up is requested', function () {
    [, $client] = stepUpApp();
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->get(stepUpRequest($client, ['max_age' => 60])['url']);

    $this->assertAuthenticatedAs($user);
    expect(LogoutNotification::count())->toBe(0);
});

it('does not accept a replayed auth_time on a later authorize request', function () {
    [$application, $client] = stepUpApp();
    $user = confirmedUser(at: Date::now()->unix());
    $user->applications()->attach($application);

    $first = stepUpRequest($client, ['max_age' => 60]);
    $code = locationQuery($this->get($first['url']))['code'];
    stepUpExchange($client, $code, $first['verifier']);

    $this->travel(5)->minutes();

    $this->get(stepUpRequest($client, ['max_age' => 60])['url'])
        ->assertRedirect(route('reauthenticate.show'));
});

it('rejects the same code a second time so its auth_time cannot be replayed', function () {
    [$application, $client] = stepUpApp();
    $user = confirmedUser();
    $user->applications()->attach($application);

    $request = stepUpRequest($client, ['max_age' => 60]);
    $code = locationQuery($this->get($request['url']))['code'];
    stepUpExchange($client, $code, $request['verifier']);

    $this->post('/oauth/token', [
        'grant_type' => 'authorization_code',
        'client_id' => $client->getKey(),
        'client_secret' => $client->plainSecret,
        'redirect_uri' => $client->redirect_uris[0],
        'code_verifier' => $request['verifier'],
        'code' => $code,
    ])->assertStatus(400);
});

it('carries no auth_time into a request that did not ask for one', function () {
    [$application, $client] = stepUpApp();
    $user = confirmedUser();
    $user->applications()->attach($application);

    $stepUp = stepUpRequest($client, ['max_age' => 60]);
    stepUpExchange($client, locationQuery($this->get($stepUp['url']))['code'], $stepUp['verifier']);

    $plain = stepUpRequest($client);
    $token = stepUpExchange($client, locationQuery($this->get($plain['url']))['code'], $plain['verifier']);

    expect(claimsOf($token['id_token'])->has('auth_time'))->toBeFalse();
});

it('leaves a request without max_age exactly as it was', function () {
    [$application, $client] = stepUpApp();
    $user = User::factory()->create();
    $user->applications()->attach($application);
    $this->actingAs($user);

    $request = stepUpRequest($client);
    $token = stepUpExchange($client, locationQuery($this->get($request['url']))['code'], $request['verifier']);

    expect(claimsOf($token['id_token'])->has('auth_time'))->toBeFalse();
});

it('does not put auth_time in the id token of a refresh', function () {
    [$application, $client] = stepUpApp();
    $user = confirmedUser();
    $user->applications()->attach($application);

    $request = stepUpRequest($client, ['max_age' => 60]);
    $token = stepUpExchange($client, locationQuery($this->get($request['url']))['code'], $request['verifier']);

    $refreshed = $this->post('/oauth/token', [
        'grant_type' => 'refresh_token',
        'refresh_token' => $token['refresh_token'],
        'client_id' => $client->getKey(),
        'client_secret' => $client->plainSecret,
    ])->assertOk()->json();

    expect($refreshed['id_token'] ?? null)->not->toBeNull()
        ->and(claimsOf($refreshed['id_token'])->has('auth_time'))->toBeFalse();
});

it('does not let a user who signs in on the same session inherit the previous confirmation', function () {
    [$application, $client] = stepUpApp();
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    $alice->applications()->attach($application);
    $bob->applications()->attach($application);

    $this->actingAs($alice)->withSession(['auth.password_confirmed_at' => Date::now()->unix()]);
    $request = stepUpRequest($client, ['max_age' => 60]);
    $aliceToken = stepUpExchange($client, locationQuery($this->get($request['url']))['code'], $request['verifier']);

    request()->setLaravelSession(app('session.store'));
    Auth::guard('web')->login($bob);

    expect(session('auth.password_confirmed_at'))->toBeNull();
    $this->get($request['url'])->assertRedirect(route('reauthenticate.show'));
    expect(claimsOf($aliceToken['id_token'])->get('sub'))->toBe((string) $alice->id);
});

it('checks the application scope allowlist before it asks for a step-up', function () {
    [$application, $client] = stepUpApp();
    $application->forceFill(['allowed_scopes' => ['identity']])->save();
    $this->actingAs(User::factory()->create());

    $this->get(stepUpRequest($client, ['max_age' => 60])['url'])->assertForbidden();
});

it('only answers an invalid step-up request to a redirect uri the client registered', function () {
    [, $client] = stepUpApp();
    [, $other] = stepUpApp('billr', 'https://billr.test/auth/sso/callback');
    $this->actingAs(User::factory()->create());

    $request = stepUpRequest($client, ['max_age' => 'abc', 'redirect_uri' => $other->redirect_uris[0]]);

    $response = $this->get($request['url'])->assertStatus(400);
    expect($response->headers->get('Location'))->toBeNull();

    $unknown = stepUpRequest($client, ['max_age' => 'abc', 'redirect_uri' => 'https://evil.test/cb']);
    $this->get($unknown['url'])->assertStatus(400)->assertHeaderMissing('Location');

    $noClient = stepUpRequest($client, ['max_age' => 'abc', 'client_id' => 'nope']);
    $this->get($noClient['url'])->assertStatus(400)->assertHeaderMissing('Location');
});

it('refuses a step-up for a client that has been revoked', function () {
    [, $client] = stepUpApp();
    $this->actingAs(User::factory()->create());
    $client->forceFill(['revoked' => true])->save();

    $this->get(stepUpRequest($client, ['max_age' => 'abc'])['url'])
        ->assertStatus(400)
        ->assertHeaderMissing('Location');
});

it('rejects max_age values that are not a plain positive integer in range', function (mixed $value) {
    [, $client] = stepUpApp();
    confirmedUser();

    $request = stepUpRequest($client, ['max_age' => $value]);
    $response = $this->get($request['url']);

    $response->assertRedirectContains('https://zero.test/auth/sso/callback');
    expect(locationQuery($response))
        ->toHaveKey('error', 'invalid_request')
        ->toHaveKey('state', 'st-123')
        ->not->toHaveKey('code');
})->with([
    'empty' => '',
    'zero' => '0',
    'negative' => '-1',
    'below the floor' => (string) (EnforceMaxAge::MIN_SECONDS - 1),
    'above the ceiling' => (string) (EnforceMaxAge::MAX_SECONDS + 1),
    'huge' => '99999999999999999999999999',
    'non numeric' => 'abc',
    'decimal' => '60.5',
    'exponent' => '1e3',
    'hex' => '0x3c',
    'signed' => '+60',
    'array' => [['60']],
    'nested array' => [[['60']]],
    'keyed array' => [['a' => '60']],
]);

it('reads a padded max_age after the framework has trimmed it', function () {
    [$application, $client] = stepUpApp();
    $user = confirmedUser();
    $user->applications()->attach($application);

    $this->get(stepUpRequest($client, ['max_age' => ' 60 '])['url'])->assertRedirectContains('code=');
});

it('accepts the lowest and highest max_age', function (int $value) {
    [$application, $client] = stepUpApp();
    $user = confirmedUser();
    $user->applications()->attach($application);

    $this->get(stepUpRequest($client, ['max_age' => $value])['url'])
        ->assertRedirectContains('code=');
})->with([EnforceMaxAge::MIN_SECONDS, EnforceMaxAge::MAX_SECONDS]);

it('uses the last value when max_age is repeated, as the rest of the stack does', function () {
    [, $client] = stepUpApp();
    confirmedUser(at: Date::now()->unix() - 120);

    $url = stepUpRequest($client)['url'];

    $this->get($url.'&max_age=3600&max_age=60')->assertRedirect(route('reauthenticate.show'));
    $this->get($url.'&max_age=60&max_age=3600')->assertRedirectContains('code=');
});

it('rejects max_age on a request that cannot produce an id token', function (string $scope) {
    [, $client] = stepUpApp();
    confirmedUser();

    $response = $this->get(stepUpRequest($client, ['max_age' => 60], $scope)['url']);

    expect(locationQuery($response))->toHaveKey('error', 'invalid_request')->not->toHaveKey('code');
})->with(['no scope' => '', 'identity only' => 'identity', 'lookalike' => 'openidx']);

it('answers prompt=none with login_required when the confirmation is stale', function () {
    [, $client] = stepUpApp();
    $user = confirmedUser(at: Date::now()->unix() - 600);

    $response = $this->get(stepUpRequest($client, ['max_age' => 60, 'prompt' => 'none'])['url']);

    $response->assertRedirectContains('https://zero.test/auth/sso/callback');
    expect(locationQuery($response))->toHaveKey('error', 'login_required')->toHaveKey('state', 'st-123');
    $this->assertAuthenticatedAs($user);
});

it('answers prompt=none with a code when the confirmation is fresh', function () {
    [$application, $client] = stepUpApp();
    $user = confirmedUser();
    $user->applications()->attach($application);

    $this->get(stepUpRequest($client, ['max_age' => 60, 'prompt' => 'none'])['url'])
        ->assertRedirectContains('code=');
});

it('treats none combined with other prompt values as none', function () {
    [, $client] = stepUpApp();
    confirmedUser(at: Date::now()->unix() - 600);

    $response = $this->get(stepUpRequest($client, ['max_age' => 60, 'prompt' => 'login none'])['url']);

    expect(locationQuery($response))->toHaveKey('error', 'login_required');
});

it('never issues a code from prompt=login plus max_age without a fresh credential', function () {
    [$application, $client] = stepUpApp();
    $user = confirmedUser();
    $user->applications()->attach($application);

    $response = $this->get(stepUpRequest($client, ['max_age' => 60, 'prompt' => 'login'])['url']);

    expect((string) $response->headers->get('Location'))->not->toContain('code=');
});

it('leaves a guest to the login flow and comes back to the check afterwards', function () {
    [, $client] = stepUpApp();

    $this->get(stepUpRequest($client, ['max_age' => 60])['url'])->assertRedirect(route('login'));
});

it('answers a guest with prompt=none and max_age with login_required', function () {
    [, $client] = stepUpApp();

    $response = $this->get(stepUpRequest($client, ['max_age' => 60, 'prompt' => 'none'])['url']);

    expect(locationQuery($response))->toHaveKey('error', 'login_required');
});

it('advertises auth_time as a supported claim', function () {
    $this->getJson('/.well-known/openid-configuration')
        ->assertOk()
        ->assertJsonPath('claims_supported', fn (array $claims) => in_array('auth_time', $claims, true));
});
