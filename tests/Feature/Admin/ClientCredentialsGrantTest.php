<?php

declare(strict_types=1);

use App\Actions\Admin\CreateApplication;
use Illuminate\Support\Facades\DB;

/**
 * Every workflow app calls ID machine-to-machine to populate its portal
 * switcher, which needs client_credentials. Passport's auth-code helper does not
 * grant it, and IdPortalClient fails soft, so the only symptom was a switcher
 * that said "No other apps available" with nothing in any log.
 */
it('grants client_credentials alongside the auth code grant', function () {
    $result = app(CreateApplication::class)->handle([
        'name' => 'Relay',
        'slug' => 'relay',
        'redirect_uri' => 'https://relay.thijssensoftware.nl/auth/sso/callback',
    ]);

    $client = DB::table('oauth_clients')->where('id', $result['client_id'])->firstOrFail();
    $grants = json_decode((string) $client->grant_types, true);

    expect($grants)->toContain('authorization_code')
        ->and($grants)->toContain('refresh_token')
        ->and($grants)->toContain('client_credentials');
});

it('does not grant it twice when created again', function () {
    $result = app(CreateApplication::class)->handle([
        'name' => 'Second',
        'slug' => 'second',
        'redirect_uri' => 'https://second.test/auth/sso/callback',
    ]);

    $grants = json_decode(
        (string) DB::table('oauth_clients')->where('id', $result['client_id'])->firstOrFail()->grant_types,
        true,
    );

    expect(array_count_values($grants)['client_credentials'])->toBe(1);
});
