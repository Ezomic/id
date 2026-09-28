<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\Request;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;

/*
 * ID-98. The step-up return path (ID-97) trusts a Referer that matches the
 * request's own host, which only means something if the app refuses hosts
 * that are not its own. nginx already drops unknown names; this is the app
 * refusing them too, in case that ever changes.
 */

const ID_HOST = 'id.thijssensoftware.nl';

beforeEach(function () {
    config(['app.url' => 'https://'.ID_HOST]);

    // Laravel stands the host check down under unit tests and in local, so
    // run these requests the way production sees them.
    app()->detectEnvironment(fn (): string => 'production');
});

afterEach(function () {
    // The trusted list is static on Symfony's Request and would otherwise
    // follow every later test in this process.
    Request::setTrustedHosts([]);
});

it('serves pages on its own host', function (string $path) {
    $this->get('https://'.ID_HOST.$path)->assertOk();
})->with(['/login', '/up', '/.well-known/openid-configuration']);

it('refuses a request for another host', function (string $host) {
    $this->get("https://{$host}/login")->assertBadRequest();
})->with([
    'another name' => 'evil.example',
    'a subdomain' => 'www.'.ID_HOST,
    'its own name as a prefix' => ID_HOST.'.evil.example',
    'a dot read as any character' => 'idxthijssensoftware.nl',
]);

it('refuses the step-up return path on another host', function () {
    $this->actingAs(User::factory()->create())
        ->withHeader('Referer', 'https://evil.example/settings/profile')
        ->get('https://evil.example/reauthenticate')
        ->assertBadRequest();
});

it('keeps the machine-to-machine endpoints working on its own host', function () {
    $client = app(ClientRepository::class)->createClientCredentialsGrantClient('Portal');

    $this->post('https://'.ID_HOST.'/oauth/token', [
        'grant_type' => 'client_credentials',
        'client_id' => $client->getKey(),
        'client_secret' => $client->plainSecret,
    ])->assertOk()->assertJsonStructure(['access_token']);

    Passport::actingAsClient($client);

    $this->postJson('https://'.ID_HOST.'/api/portal/apps', ['email' => 'nobody@example.com'])->assertOk();
});

it('refuses the machine-to-machine endpoints on another host', function () {
    $client = app(ClientRepository::class)->createClientCredentialsGrantClient('Portal');

    $this->post('https://evil.example/oauth/token', [
        'grant_type' => 'client_credentials',
        'client_id' => $client->getKey(),
        'client_secret' => $client->plainSecret,
    ])->assertBadRequest();

    $this->getJson('https://evil.example/api/userinfo')->assertBadRequest();
    $this->postJson('https://evil.example/api/portal/apps', ['email' => 'nobody@example.com'])->assertBadRequest();
});
