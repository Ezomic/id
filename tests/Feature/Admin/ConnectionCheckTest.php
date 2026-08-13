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

/** A consumer on id-client 0.3 or later: it reads the event and ignores one it does not know. */
function fakeModernConsumer(): void
{
    Http::fake(['*' => Http::response(['status' => 'ignored'])]);
}

/** A consumer on 0.2: it accepts anything signed and ends the session regardless. */
function fakeLegacyConsumer(): void
{
    Http::fake(['*' => Http::response(['status' => 'ok'])]);
}

it('reports a correctly wired app as healthy', function () {
    fakeModernConsumer();

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

it('detects a consumer that reads the event field', function () {
    fakeModernConsumer();
    $application = wiredApp();

    $check = checkOf($application, 'Event handling');

    expect($check['ok'])->toBeTrue()
        ->and($application->fresh()->understandsTypedEvents())->toBeTrue();
});

it('detects a consumer that ends the session on any event', function () {
    fakeLegacyConsumer();
    $application = wiredApp();

    $check = checkOf($application, 'Event handling');

    expect($check['ok'])->toBeFalse()
        ->and($check['detail'])->toContain('id-client 0.3 or later')
        ->and($application->fresh()->understandsTypedEvents())->toBeFalse();
});

it('does not blame the dialect when the endpoint never answered', function () {
    Http::fake(['*' => Http::response('', 404)]);
    $application = wiredApp();

    $check = checkOf($application, 'Event handling');

    // A consumer with no route to accept a call cannot be ending sessions on
    // anything, whatever version it is on.
    expect($check['ok'])->toBeFalse()
        ->and($check['detail'])->toContain('No answer to probe')
        ->and($check['detail'])->not->toContain('id-client 0.3')
        ->and($application->fresh()->understandsTypedEvents())->toBeFalse();
});

it('withdraws confirmation when a consumer is rolled back', function () {
    // One stub whose answer changes, because a second Http::fake() call is
    // merged behind the first rather than replacing it.
    $consumer = new stdClass;
    $consumer->status = 'ignored';
    Http::fake(fn () => Http::response(['status' => $consumer->status]));

    $application = wiredApp();
    app(CheckApplicationConnection::class)->handle($application);
    expect($application->fresh()->understandsTypedEvents())->toBeTrue();

    // A downgrade is as much a fact about the consumer as an upgrade, so the
    // check has to be able to take the confirmation away again.
    $consumer->status = 'ok';
    app(CheckApplicationConnection::class)->handle($application);

    expect($application->fresh()->understandsTypedEvents())->toBeFalse();
});

it('probes event handling without touching a real session', function () {
    fakeModernConsumer();

    app(CheckApplicationConnection::class)->handle(wiredApp());

    Http::assertSent(function ($request) {
        $payload = json_decode($request->body(), true);

        return ($payload['event'] ?? null) === 'connection.probe'
            && str_starts_with($payload['sub'], 'connection-probe-');
    });
});

it('runs from the command line', function () {
    fakeModernConsumer();
    wiredApp();

    $this->artisan('id:check', ['slug' => 'zero'])->assertSuccessful();
});

it('exits non-zero when something needs attention', function () {
    Http::fake(['*' => Http::response('', 404)]);
    wiredApp();

    $this->artisan('id:check', ['slug' => 'zero'])->assertFailed();
});

it('runs from the admin screen and is closed to non-admins', function () {
    fakeModernConsumer();
    $application = wiredApp();

    $this->actingAs(User::factory()->admin()->create())
        ->post(route('admin.applications.check', $application))
        ->assertRedirect()
        ->assertSessionHas('connectionCheck');

    $this->actingAs(User::factory()->create())
        ->post(route('admin.applications.check', $application))
        ->assertForbidden();
});
