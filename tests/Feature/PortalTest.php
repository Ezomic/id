<?php

use App\Models\Application;
use App\Models\Bookmark;
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

it('shows the users active bookmarks on the portal, newest first', function () {
    $user = User::factory()->create();
    Bookmark::factory()->for($user)->create(['title' => 'Older']);
    $newest = Bookmark::factory()->for($user)->create(['title' => 'Newest']);
    $newest->forceFill(['created_at' => now()->addMinute()])->save();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('bookmarks', 2)
            ->where('bookmarks.0.title', 'Newest')
            ->where('bookmarks.1.title', 'Older')
        );
});

it('excludes archived bookmarks and other peoples bookmarks from the portal', function () {
    $user = User::factory()->create();
    Bookmark::factory()->for($user)->create(['title' => 'Mine']);
    Bookmark::factory()->for($user)->archived()->create(['title' => 'Archived']);
    Bookmark::factory()->create(['title' => 'Someone else']);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('bookmarks', 1)
            ->where('bookmarks.0.title', 'Mine')
        );
});
