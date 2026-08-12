<?php

use App\Models\AccessAudit;
use App\Models\Application;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Token;

beforeEach(fn () => confirmSession());

/**
 * @return array{0: Application, 1: Client}
 */
function registeredApp(string $slug = 'zero'): array
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
        'active' => true,
    ]);

    return [$application, $client];
}

function tokenFor(Application $application, Client $client, User $user): array
{
    $user->applications()->syncWithoutDetaching([$application->id]);

    $verifier = Str::random(64);
    $challenge = strtr(rtrim(base64_encode(hash('sha256', $verifier, true)), '='), '+/', '-_');

    $response = test()->actingAs($user)->get('/oauth/authorize?'.http_build_query([
        'client_id' => $client->getKey(),
        'redirect_uri' => $client->redirect_uris[0],
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
        'redirect_uri' => $client->redirect_uris[0],
        'code_verifier' => $verifier,
        'code' => $query['code'],
    ])->assertOk()->json();

    app('auth')->forgetGuards();

    return $token;
}

it('rotates the secret from the command line', function () {
    [$application, $client] = registeredApp();
    $before = $client->getAttributes()['secret'];

    $this->artisan('id:app', ['--rotate' => 'zero'])
        ->expectsOutputToContain('Rotated the secret for [zero].')
        ->assertSuccessful();

    expect($client->fresh()?->getAttributes()['secret'])->not->toBe($before);
});

it('refuses to rotate an application with no OAuth client', function () {
    Application::create(['name' => 'Orphan', 'slug' => 'orphan', 'active' => true]);

    $this->artisan('id:app', ['--rotate' => 'orphan'])
        ->expectsOutputToContain('has no OAuth client to rotate')
        ->assertFailed();
});

it('refuses to rotate an unknown slug', function () {
    $this->artisan('id:app', ['--rotate' => 'nope'])->assertFailed();
});

it('still requires every argument when registering', function () {
    $this->artisan('id:app', ['name' => 'Zero'])->assertFailed();
});

it('revokes tokens issued under the old secret', function () {
    [$application, $client] = registeredApp();
    $user = User::factory()->create();

    tokenFor($application, $client, $user);

    expect(Token::where('client_id', $client->getKey())->count())->toBe(1);

    $this->artisan('id:app', ['--rotate' => 'zero'])->assertSuccessful();

    expect(Token::where('client_id', $client->getKey())->count())->toBe(0);
});

it('lets an admin rotate from the applications screen', function () {
    [$application, $client] = registeredApp();
    $admin = User::factory()->admin()->create();
    $before = $client->getAttributes()['secret'];

    $this->actingAs($admin)
        ->post(route('admin.applications.rotate-secret', $application))
        ->assertRedirect()
        ->assertSessionHas('createdClient');

    expect($client->fresh()?->getAttributes()['secret'])->not->toBe($before);
    expect(AccessAudit::where('action', 'client_secret_rotate')->where('application_id', $application->id)->exists())->toBeTrue();
});

it('reveals the logout secret when rotating', function () {
    [$application] = registeredApp();
    $admin = User::factory()->admin()->create();
    $before = $application->logout_secret;

    $response = $this->actingAs($admin)
        ->post(route('admin.applications.rotate-secret', $application))
        ->assertRedirect();

    $flashed = $response->getSession()->get('createdClient');

    // Without this the rotation silently invalidates a working logout secret
    // and never shows the replacement, so single logout breaks with no way to
    // recover the value from the UI.
    expect($flashed['logout_secret'])->not->toBeNull()
        ->and($flashed['logout_secret'])->not->toBe($before)
        ->and($flashed['logout_secret'])->toBe($application->fresh()?->logout_secret);
});

it('reveals the logout secret when registering', function () {
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->post(route('admin.applications.store'), [
        'name' => 'Scratch',
        'slug' => 'scratch',
        'redirect_uri' => 'https://scratch.test/auth/sso/callback',
    ])->assertRedirect();

    $flashed = $response->getSession()->get('createdClient');

    expect($flashed['logout_secret'])->not->toBeNull()
        ->and($flashed['logout_secret'])->toBe(Application::where('slug', 'scratch')->value('logout_secret'));
});

it('keeps rotation away from non-admins', function () {
    [$application] = registeredApp();

    $this->actingAs(User::factory()->create())
        ->post(route('admin.applications.rotate-secret', $application))
        ->assertForbidden();
});

it('blocks the token endpoint once an application is deactivated', function () {
    [$application, $client] = registeredApp();
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();
    $user->applications()->attach($application);

    $this->actingAs($admin)->put(route('admin.applications.update', $application), [
        'name' => $application->name,
        'slug' => $application->slug,
        'redirect_uri' => $client->redirect_uris[0],
        'active' => false,
    ])->assertRedirect();

    app('auth')->forgetGuards();

    expect($client->fresh()?->revoked)->toBeTrue();

    $this->post('/oauth/token', [
        'grant_type' => 'client_credentials',
        'client_id' => $client->getKey(),
        'client_secret' => $client->plainSecret,
    ])->assertStatus(401);
});

it('blocks the authorize endpoint once an application is deactivated', function () {
    [$application, $client] = registeredApp();
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();
    $user->applications()->attach($application);

    $this->actingAs($admin)->put(route('admin.applications.update', $application), [
        'name' => $application->name,
        'slug' => $application->slug,
        'redirect_uri' => $client->redirect_uris[0],
        'active' => false,
    ]);

    app('auth')->forgetGuards();

    $this->actingAs($user)->get('/oauth/authorize?'.http_build_query([
        'client_id' => $client->getKey(),
        'redirect_uri' => $client->redirect_uris[0],
        'response_type' => 'code',
        'scope' => '',
    ]))->assertStatus(401);
});

it('revokes live tokens when an application is deactivated, and audits it', function () {
    [$application, $client] = registeredApp();
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();

    tokenFor($application, $client, $user);
    expect(Token::where('client_id', $client->getKey())->count())->toBe(1);

    $this->actingAs($admin)->put(route('admin.applications.update', $application), [
        'name' => $application->name,
        'slug' => $application->slug,
        'redirect_uri' => $client->redirect_uris[0],
        'active' => false,
    ]);

    expect(Token::where('client_id', $client->getKey())->count())->toBe(0)
        ->and(AccessAudit::where('action', 'app_disable')->exists())->toBeTrue();
});

it('re-enables a client and audits that too', function () {
    [$application, $client] = registeredApp();
    $admin = User::factory()->admin()->create();

    $payload = [
        'name' => $application->name,
        'slug' => $application->slug,
        'redirect_uri' => $client->redirect_uris[0],
    ];

    $this->actingAs($admin)->put(route('admin.applications.update', $application), [...$payload, 'active' => false]);
    $this->actingAs($admin)->put(route('admin.applications.update', $application), [...$payload, 'active' => true]);

    expect($client->fresh()?->revoked)->toBeFalse()
        ->and(AccessAudit::where('action', 'app_enable')->exists())->toBeTrue();
});
