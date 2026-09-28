<?php

declare(strict_types=1);

use App\Actions\Admin\CreateApplication;
use App\Actions\Estate\CreateEstateReader;
use App\Models\Application;
use App\Models\Group;
use App\Models\OAuthClient;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;

/*
 * ID-55. Atlas reads which apps are registered with ID and who can reach
 * them, with one client-credentials scope and nothing else: no admin session,
 * no database access, and never a secret.
 */

function estateReader(): Client
{
    return app(CreateEstateReader::class)->handle('Atlas');
}

/**
 * An ordinary workflow app's client and its plain secret. It has
 * client_credentials for the portal switcher, which is exactly why it must
 * not be able to read the estate.
 *
 * @return array{0: Client, 1: string}
 */
function estateAppClient(): array
{
    $result = app(CreateApplication::class)->handle([
        'name' => 'Zero',
        'slug' => 'zero',
        'redirect_uri' => 'https://zero.thijssensoftware.nl/auth/sso/callback',
    ]);

    return [OAuthClient::findOrFail($result['client_id']), $result['client_secret']];
}

function estateToken(Client $client, string $secret, string $scope): string
{
    return test()->post('/oauth/token', [
        'grant_type' => 'client_credentials',
        'client_id' => $client->getKey(),
        'client_secret' => $secret,
        'scope' => $scope,
    ])->assertOk()->json('access_token');
}

/**
 * The scopes the token was actually issued with, read from its claims.
 *
 * @return list<string>
 */
function estateTokenScopes(string $token): array
{
    $payload = explode('.', $token)[1];

    return json_decode(base64_decode(strtr($payload, '-_', '+/')), true, flags: JSON_THROW_ON_ERROR)['scopes'];
}

it('refuses a request without a token', function () {
    $this->getJson('/api/admin/estate')->assertUnauthorized();
});

it('refuses a user token, even one that carries the scope', function () {
    Passport::actingAs(User::factory()->create(), ['estate:read']);

    $this->getJson('/api/admin/estate')->assertUnauthorized();
});

it('refuses a reader token that does not carry the scope', function () {
    Passport::actingAsClient(estateReader(), []);

    $this->getJson('/api/admin/estate')->assertForbidden();
});

it('refuses a token whose client was never granted the scope', function () {
    [$client] = estateAppClient();

    Passport::actingAsClient($client, ['estate:read']);

    $this->getJson('/api/admin/estate')->assertForbidden();
});

it('issues estate:read to a registered reader', function () {
    $reader = estateReader();
    $token = estateToken($reader, (string) $reader->plainSecret, 'estate:read');

    expect(estateTokenScopes($token))->toBe(['estate:read']);

    $this->withToken($token)
        ->getJson('/api/admin/estate')
        ->assertOk()
        ->assertJsonStructure(['applications', 'grants']);
});

it('does not issue estate:read to an app client', function (string $scope) {
    [$client, $secret] = estateAppClient();
    $token = estateToken($client, $secret, $scope);

    expect(estateTokenScopes($token))->toBe([]);

    $this->withToken($token)->getJson('/api/admin/estate')->assertForbidden();
})->with(['by name' => 'estate:read', 'through the wildcard' => '*']);

it('does not issue the wildcard to a reader either', function () {
    $reader = estateReader();
    $token = estateToken($reader, (string) $reader->plainSecret, '*');

    expect(estateTokenScopes($token))->toBe([]);

    $this->withToken($token)->getJson('/api/admin/estate')->assertForbidden();
});

it('lists every application with its client and redirect hosts', function () {
    Carbon::setTestNow('2026-09-01 10:00:00');
    $zero = app(CreateApplication::class)->handle([
        'name' => 'Zero',
        'slug' => 'zero',
        'redirect_uri' => 'https://zero.thijssensoftware.nl/auth/sso/callback',
    ]);
    OAuthClient::findOrFail($zero['client_id'])->forceFill(['redirect_uris' => [
        'https://zero.thijssensoftware.nl/auth/sso/callback',
        'https://mail.thijssensoftware.nl/auth/sso/callback',
        'https://zero.thijssensoftware.nl/other/callback',
    ]])->save();
    Carbon::setTestNow('2026-09-03 12:00:00');
    Application::create(['name' => 'Old', 'slug' => 'old', 'active' => false]);
    Carbon::setTestNow();

    Passport::actingAsClient(estateReader(), ['estate:read']);

    $this->getJson('/api/admin/estate')
        ->assertOk()
        ->assertJsonPath('applications', [
            [
                'slug' => 'old',
                'name' => 'Old',
                'active' => false,
                'client_id' => null,
                'redirect_hosts' => [],
                'created_at' => '2026-09-03T12:00:00+00:00',
            ],
            [
                'slug' => 'zero',
                'name' => 'Zero',
                'active' => true,
                'client_id' => $zero['client_id'],
                'redirect_hosts' => ['zero.thijssensoftware.nl', 'mail.thijssensoftware.nl'],
                'created_at' => '2026-09-01T10:00:00+00:00',
            ],
        ]);
});

it('lists direct grants and grants inherited from a group', function () {
    $billr = Application::create(['name' => 'Billr', 'slug' => 'billr', 'active' => true]);
    $zero = Application::create(['name' => 'Zero', 'slug' => 'zero', 'active' => true]);
    $ann = User::factory()->create(['email' => 'ann@example.com']);
    $bob = User::factory()->create(['email' => 'bob@example.com']);

    Carbon::setTestNow('2026-09-02 08:30:00');
    $bob->applications()->attach($zero);
    Carbon::setTestNow();

    $team = Group::create(['name' => 'Team']);
    $team->applications()->attach($billr);
    $team->users()->attach([$ann->id, $bob->id]);

    Passport::actingAsClient(estateReader(), ['estate:read']);

    $this->getJson('/api/admin/estate')
        ->assertOk()
        ->assertJsonPath('grants', [
            ['user' => 'ann@example.com', 'application' => 'billr', 'via' => 'group', 'group' => 'team', 'granted_at' => null],
            ['user' => 'bob@example.com', 'application' => 'billr', 'via' => 'group', 'group' => 'team', 'granted_at' => null],
            ['user' => 'bob@example.com', 'application' => 'zero', 'via' => 'direct', 'group' => null, 'granted_at' => '2026-09-02T08:30:00+00:00'],
        ]);
});

it('never returns a secret or a token', function () {
    $user = User::factory()->create(['email' => 'ann@example.com']);
    $result = app(CreateApplication::class)->handle([
        'name' => 'Zero',
        'slug' => 'zero',
        'redirect_uri' => 'https://zero.thijssensoftware.nl/auth/sso/callback',
        'users' => [$user->id],
    ]);

    $reader = estateReader();
    $token = estateToken($reader, (string) $reader->plainSecret, 'estate:read');

    $body = (string) $this->withToken($token)->getJson('/api/admin/estate')->assertOk()->getContent();

    expect($body)->toContain('ann@example.com')
        ->and($body)->not->toMatch('/secret|token/i')
        ->and($body)->not->toContain($result['client_secret'])
        ->and($body)->not->toContain($result['logout_secret'])
        ->and($body)->not->toContain((string) $reader->plainSecret)
        ->and($body)->not->toContain($token);

    $stored = [
        ...DB::table('oauth_clients')->whereNotNull('secret')->pluck('secret'),
        ...DB::table('oauth_access_tokens')->pluck('id'),
    ];

    expect($stored)->not->toBeEmpty();

    foreach ($stored as $value) {
        expect($body)->not->toContain((string) $value);
    }
});

it('is rate limited per client', function () {
    Passport::actingAsClient(estateReader(), ['estate:read']);

    foreach (range(1, 30) as $ignored) {
        $this->getJson('/api/admin/estate')->assertOk();
    }

    $this->getJson('/api/admin/estate')->assertTooManyRequests();
});

it('registers a reader that holds only estate:read and only client_credentials', function () {
    $this->artisan('id:estate-reader', ['name' => 'Atlas'])
        ->expectsOutputToContain('client id')
        ->expectsOutputToContain('client secret')
        ->assertSuccessful();

    $client = OAuthClient::where('name', 'Atlas')->sole();

    expect($client->scopes)->toBe(['estate:read'])
        ->and($client->grant_types)->toBe(['client_credentials'])
        ->and($client->redirect_uris)->toBe([]);
});
