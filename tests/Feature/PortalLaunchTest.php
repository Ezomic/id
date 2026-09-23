<?php

use App\Actions\Admin\CreateApplication;
use App\Models\Application;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

it('derives a launch url from the callback when none is given', function () {
    $result = app(CreateApplication::class)->handle([
        'name' => 'Atlas',
        'slug' => 'atlas',
        'redirect_uri' => 'https://atlas.thijssensoftware.nl/auth/sso/callback',
    ]);

    expect($result['application']->launch_url)->toBe('https://atlas.thijssensoftware.nl');
});

it('keeps the port from a local callback', function () {
    $result = app(CreateApplication::class)->handle([
        'name' => 'Atlas',
        'slug' => 'atlas-local',
        'redirect_uri' => 'http://atlas.test:8080/auth/sso/callback',
    ]);

    expect($result['application']->launch_url)->toBe('http://atlas.test:8080');
});

it('prefers an explicitly supplied launch url', function () {
    $result = app(CreateApplication::class)->handle([
        'name' => 'CMS',
        'slug' => 'cms',
        'redirect_uri' => 'https://cms.thijssensoftware.nl/auth/sso/callback',
        'launch_url' => 'https://thijssensoftware.nl',
    ]);

    expect($result['application']->launch_url)->toBe('https://thijssensoftware.nl');
});

it('leaves the launch url null when the callback has no host', function () {
    $result = app(CreateApplication::class)->handle([
        'name' => 'Broken',
        'slug' => 'broken',
        'redirect_uri' => '/auth/sso/callback',
    ]);

    expect($result['application']->launch_url)->toBeNull();
});

/**
 * The deadlock this fixes: the admin auto-grant only runs during the userinfo
 * call, which needs a completed SSO round trip, which the portal used to
 * refuse because no grant existed yet.
 */
it('lets an admin launch an application they have no grant for', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $application = Application::create([
        'name' => 'Atlas',
        'slug' => 'atlas',
        'launch_url' => 'https://atlas.thijssensoftware.nl',
        'active' => true,
    ]);

    $this->actingAs($admin)
        ->get(route('portal.launch', $application))
        ->assertRedirect('https://atlas.thijssensoftware.nl');
});

it('does not let an admin launch an inactive application', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $application = Application::create([
        'name' => 'Retired',
        'slug' => 'retired',
        'launch_url' => 'https://retired.thijssensoftware.nl',
        'active' => false,
    ]);

    $this->actingAs($admin)
        ->get(route('portal.launch', $application))
        ->assertForbidden();
});

it('still refuses a non-admin with no grant', function () {
    $user = User::factory()->create(['is_admin' => false]);
    $application = Application::create([
        'name' => 'Atlas',
        'slug' => 'atlas',
        'launch_url' => 'https://atlas.thijssensoftware.nl',
        'active' => true,
    ]);

    $this->actingAs($user)
        ->get(route('portal.launch', $application))
        ->assertForbidden();
});

it('refuses to launch an application with no launch url', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $application = Application::create(['name' => 'Atlas', 'slug' => 'atlas', 'active' => true]);

    $this->actingAs($admin)
        ->get(route('portal.launch', $application))
        ->assertForbidden();
});

it('shows every active application as reachable to an admin', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    Application::create(['name' => 'Atlas', 'slug' => 'atlas', 'active' => true]);
    Application::create(['name' => 'Zero', 'slug' => 'zero', 'active' => true]);

    $this->actingAs($admin)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('accessibleCount', 2)
            ->where('applications.0.can_access', true)
            ->where('applications.1.can_access', true)
        );
});
