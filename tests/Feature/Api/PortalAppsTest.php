<?php

use App\Models\Application;
use App\Models\PortalLookup;
use App\Models\User;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;

function actingAsPortalClient(string $name = 'Test Portal Client'): Client
{
    $client = app(ClientRepository::class)->createClientCredentialsGrantClient($name);

    Passport::actingAsClient($client);

    return $client;
}

it('rejects requests without a client token', function () {
    $this->postJson('/api/portal/apps', ['email' => 'nobody@example.com'])
        ->assertUnauthorized();
});

it('accepts any first-party client-credentials token', function () {
    actingAsPortalClient();

    $this->postJson('/api/portal/apps', ['email' => 'nobody@example.com'])
        ->assertOk()
        ->assertExactJson(['applications' => [], 'categories' => []]);
});

it('returns the launchable apps a user can access', function () {
    actingAsPortalClient();

    $user = User::factory()->create();

    $accessible = Application::create([
        'name' => 'Billr',
        'slug' => 'billr',
        'accent' => '#4f46e5',
        'launch_url' => 'https://billr.thijssensoftware.nl',
        'active' => true,
    ]);
    $user->applications()->attach($accessible);

    // Not accessible to this user.
    Application::create([
        'name' => 'Zero',
        'slug' => 'zero',
        'launch_url' => 'https://zero.thijssensoftware.nl',
        'active' => true,
    ]);

    // Accessible but not launchable (no launch_url).
    $noLaunch = Application::create(['name' => 'Ghost', 'slug' => 'ghost', 'active' => true]);
    $user->applications()->attach($noLaunch);

    // Accessible and launchable but inactive.
    $inactive = Application::create([
        'name' => 'Old',
        'slug' => 'old',
        'launch_url' => 'https://old.thijssensoftware.nl',
        'active' => false,
    ]);
    $user->applications()->attach($inactive);

    $this->postJson('/api/portal/apps', ['email' => $user->email])
        ->assertOk()
        ->assertExactJson([
            'applications' => [
                [
                    'slug' => 'billr',
                    'name' => 'Billr',
                    'initials' => 'B',
                    'accent' => '#4f46e5',
                    'launch_url' => 'https://billr.thijssensoftware.nl',
                ],
            ],
            'categories' => [],
        ]);
});

it('splits categorized apps into their own groups', function () {
    actingAsPortalClient();

    $user = User::factory()->create();

    $billr = Application::create([
        'name' => 'Billr',
        'slug' => 'billr',
        'accent' => '#4f46e5',
        'launch_url' => 'https://billr.thijssensoftware.nl',
        'active' => true,
    ]);

    $chess = Application::create([
        'name' => 'Chess',
        'slug' => 'chess',
        'accent' => null,
        'launch_url' => 'https://chess.thijssensoftware.nl',
        'category' => 'Games',
        'active' => true,
    ]);

    $user->applications()->attach([$billr->id, $chess->id]);

    $this->postJson('/api/portal/apps', ['email' => $user->email])
        ->assertOk()
        ->assertExactJson([
            'applications' => [
                [
                    'slug' => 'billr',
                    'name' => 'Billr',
                    'initials' => 'B',
                    'accent' => '#4f46e5',
                    'launch_url' => 'https://billr.thijssensoftware.nl',
                ],
            ],
            'categories' => [
                [
                    'category' => 'Games',
                    'apps' => [
                        [
                            'slug' => 'chess',
                            'name' => 'Chess',
                            'initials' => 'C',
                            'accent' => null,
                            'launch_url' => 'https://chess.thijssensoftware.nl',
                        ],
                    ],
                ],
            ],
        ]);
});

it('returns an empty list for an unknown email', function () {
    actingAsPortalClient();

    $this->postJson('/api/portal/apps', ['email' => 'unknown@example.com'])
        ->assertOk()
        ->assertExactJson(['applications' => [], 'categories' => []]);
});

it('validates the email', function () {
    actingAsPortalClient();

    $this->postJson('/api/portal/apps', ['email' => 'not-an-email'])
        ->assertUnprocessable()
        ->assertJsonValidationErrorFor('email');
});

it('records every lookup against the calling client', function () {
    $client = actingAsPortalClient();
    $user = User::factory()->create();

    $this->postJson('/api/portal/apps', ['email' => $user->email])->assertOk();

    $lookup = PortalLookup::first();

    expect($lookup)->not->toBeNull()
        ->and($lookup->oauth_client_id)->toBe((string) $client->getKey())
        ->and($lookup->subject_email)->toBe($user->email)
        ->and($lookup->matched)->toBeTrue();
});

it('records a lookup that matched no account', function () {
    actingAsPortalClient();

    $this->postJson('/api/portal/apps', ['email' => 'nobody@example.com'])->assertOk();

    // Recording the misses is the point: probing for accounts that do not exist
    // is what enumeration looks like.
    expect(PortalLookup::first()?->subject_email)->toBe('nobody@example.com')
        ->and(PortalLookup::first()?->matched)->toBeFalse();
});

it('meters lookups per client', function () {
    actingAsPortalClient();

    foreach (range(1, 60) as $i) {
        $this->postJson('/api/portal/apps', ['email' => "probe{$i}@example.com"])->assertOk();
    }

    $this->postJson('/api/portal/apps', ['email' => 'probe61@example.com'])->assertStatus(429);
});

it('does not let one client exhaust another client\'s allowance', function () {
    actingAsPortalClient('First');

    foreach (range(1, 60) as $i) {
        $this->postJson('/api/portal/apps', ['email' => "probe{$i}@example.com"]);
    }

    $this->postJson('/api/portal/apps', ['email' => 'blocked@example.com'])->assertStatus(429);

    actingAsPortalClient('Second');

    $this->postJson('/api/portal/apps', ['email' => 'allowed@example.com'])->assertOk();
});
