<?php

use App\Models\Application;
use Illuminate\Support\Str;
use Laravel\Passport\ClientRepository;

/**
 * @param  list<string>  $redirectUris
 */
function endpointApp(array $redirectUris, ?string $launchUrl): Application
{
    $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient(
        name: 'Zero',
        redirectUris: $redirectUris,
        confidential: true,
    );

    return Application::create([
        'name' => 'Zero',
        'slug' => 'zero',
        'oauth_client_id' => $client->getKey(),
        'logout_secret' => Str::random(64),
        'launch_url' => $launchUrl,
        'active' => true,
    ]);
}

it('derives both endpoints from the callback matching the launch host', function () {
    // Registration order puts a host the app is not served on first, which is
    // exactly zero's production state: the launch went to one host and the
    // back-channel logout to another that has no DNS record.
    $application = endpointApp([
        'https://mail.thijssensoftware.nl/auth/sso/callback',
        'https://zero.thijssensoftware.nl/auth/sso/callback',
    ], 'https://zero.thijssensoftware.nl');

    expect($application->ssoLaunchUrl())->toBe('https://zero.thijssensoftware.nl/auth/sso/redirect')
        ->and($application->logoutUrl())->toBe('https://zero.thijssensoftware.nl/auth/sso/logout');
});

it('falls back to the first callback when no launch URL is set', function () {
    $application = endpointApp(['https://zero.test/auth/sso/callback'], null);

    expect($application->ssoLaunchUrl())->toBe('https://zero.test/auth/sso/redirect')
        ->and($application->logoutUrl())->toBe('https://zero.test/auth/sso/logout');
});

it('has no endpoints when the redirect URI does not follow the convention', function () {
    $application = endpointApp(['https://status.thijssensoftware.nl/auth/callback'], 'https://status.thijssensoftware.nl');

    expect($application->ssoLaunchUrl())->toBeNull()
        ->and($application->logoutUrl())->toBeNull();
});

it('keeps the two endpoints on one host whatever the launch URL says', function () {
    // A launch_url pointing somewhere with no registered callback must not
    // split the pair: whichever is chosen, both have to agree.
    $application = endpointApp([
        'https://one.test/auth/sso/callback',
        'https://two.test/auth/sso/callback',
    ], 'https://elsewhere.test');

    expect(parse_url((string) $application->ssoLaunchUrl(), PHP_URL_HOST))
        ->toBe(parse_url((string) $application->logoutUrl(), PHP_URL_HOST));
});
