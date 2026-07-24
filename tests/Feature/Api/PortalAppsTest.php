<?php

use App\Models\Application;
use App\Models\User;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;

function actingAsPortalClient(): void
{
    $client = app(ClientRepository::class)->createClientCredentialsGrantClient('Test Portal Client');

    Passport::actingAsClient($client);
}

it('rejects requests without a client token', function () {
    $this->postJson('/api/portal/apps', ['email' => 'nobody@example.com'])
        ->assertUnauthorized();
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
        ]);
});

it('returns an empty list for an unknown email', function () {
    actingAsPortalClient();

    $this->postJson('/api/portal/apps', ['email' => 'unknown@example.com'])
        ->assertOk()
        ->assertExactJson(['applications' => []]);
});

it('validates the email', function () {
    actingAsPortalClient();

    $this->postJson('/api/portal/apps', ['email' => 'not-an-email'])
        ->assertUnprocessable()
        ->assertJsonValidationErrorFor('email');
});
