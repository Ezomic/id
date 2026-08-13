<?php

use App\Actions\Admin\CheckApplicationConnection;
use App\Models\Application;
use App\Models\LogoutNotification;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Passport\ClientRepository;

beforeEach(fn () => confirmSession());

function wiredApp(array $overrides = []): Application
{
    $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient(
        name: 'Zero',
        redirectUris: ['https://zero.test/auth/sso/callback'],
        confidential: true,
    );

    return Application::create([
        'name' => 'Zero',
        'slug' => 'zero',
        'oauth_client_id' => $client->getKey(),
        'logout_secret' => Str::random(64),
        'active' => true,
        ...$overrides,
    ]);
}

function checkOf(Application $application, string $name): array
{
    $result = app(CheckApplicationConnection::class)->handle($application);

    return collect($result['checks'])->firstWhere('name', $name);
}

it('reports a correctly wired app as healthy', function () {
    Http::fake();

    expect(app(CheckApplicationConnection::class)->handle(wiredApp())['healthy'])->toBeTrue();
});

it('never signs anyone out when probing', function () {
    Http::fake();
    $application = wiredApp();

    app(CheckApplicationConnection::class)->handle($application);

    Http::assertSent(function ($request) {
        $payload = json_decode($request->body(), true);

        // A subject no account can have, so a consumer that verifies the
        // signature accepts the call and finds nobody to sign out.
        return str_starts_with($payload['sub'], 'connection-probe-');
    });
});

it('says to redeploy when the endpoint is missing', function () {
    Http::fake(['*' => Http::response('', 404)]);

    expect(checkOf(wiredApp(), 'Logout endpoint')['detail'])
        ->toContain('Redeploy with id-client 0.2');
});

it('says to set the secret when the consumer has none', function () {
    Http::fake(['*' => Http::response('', 501)]);

    expect(checkOf(wiredApp(), 'Logout endpoint')['detail'])
        ->toContain('THIJSSENSOFTWARE_ID_LOGOUT_SECRET');
});

it('says to rotate when the signature is rejected', function () {
    Http::fake(['*' => Http::response('', 401)]);

    expect(checkOf(wiredApp(), 'Logout endpoint')['detail'])->toContain('rotate and redeploy');
});

it('treats an unreachable consumer as a result, not an exception', function () {
    Http::fake(fn () => throw new ConnectionException('timed out'));

    $check = checkOf(wiredApp(), 'Logout endpoint');

    expect($check['ok'])->toBeFalse()
        ->and($check['detail'])->toContain('Unreachable');
});

it('flags a missing logout secret', function () {
    Http::fake();

    expect(checkOf(wiredApp(['logout_secret' => null]), 'Logout secret')['ok'])->toBeFalse();
});

it('flags an unconventional redirect URI', function () {
    Http::fake();
    $application = Application::create([
        'name' => 'Odd',
        'slug' => 'odd',
        'logout_secret' => Str::random(64),
        'active' => true,
    ]);

    expect(checkOf($application, 'Redirect URI')['ok'])->toBeFalse();
});

it('flags outstanding logout deliveries', function () {
    Http::fake();
    $application = wiredApp();

    LogoutNotification::create([
        'user_id' => User::factory()->create()->id,
        'application_id' => $application->id,
        'endpoint' => 'https://zero.test/auth/sso/logout',
    ]);

    expect(checkOf($application, 'Logout deliveries')['ok'])->toBeFalse();
});

it('runs from the command line', function () {
    Http::fake();
    wiredApp();

    $this->artisan('id:check', ['slug' => 'zero'])->assertSuccessful();
});

it('exits non-zero when something needs attention', function () {
    Http::fake(['*' => Http::response('', 404)]);
    wiredApp();

    $this->artisan('id:check', ['slug' => 'zero'])->assertFailed();
});

it('runs from the admin screen and is closed to non-admins', function () {
    Http::fake();
    $application = wiredApp();

    $this->actingAs(User::factory()->admin()->create())
        ->post(route('admin.applications.check', $application))
        ->assertRedirect()
        ->assertSessionHas('connectionCheck');

    $this->actingAs(User::factory()->create())
        ->post(route('admin.applications.check', $application))
        ->assertForbidden();
});
