<?php

use App\Models\Application;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

it('shows the portal with per-app access flags', function () {
    $user = User::factory()->create();
    $accessible = Application::create(['name' => 'Zero', 'slug' => 'zero', 'active' => true]);
    Application::create(['name' => 'Shop', 'slug' => 'shop', 'active' => true]);
    Application::create(['name' => 'Hidden', 'slug' => 'hidden', 'active' => false]);
    $user->applications()->attach($accessible);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Dashboard')
            ->where('accessibleCount', 1)
            ->has('applications', 2)
            ->where('applications.0.name', 'Shop')
            ->where('applications.0.can_access', false)
            ->where('applications.1.name', 'Zero')
            ->where('applications.1.can_access', true)
        );
});
