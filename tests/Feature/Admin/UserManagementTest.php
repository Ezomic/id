<?php

use App\Models\Application;
use App\Models\User;

it('lets an admin create a user with app access', function () {
    $admin = User::factory()->admin()->create();
    $app = Application::create(['name' => 'Tracker', 'slug' => 'tracker']);

    $this->actingAs($admin)
        ->post(route('admin.users.store'), [
            'name' => 'New Person',
            'email' => 'new@example.com',
            'is_admin' => false,
            'applications' => [$app->id],
        ])
        ->assertSessionHasNoErrors();

    $user = User::where('email', 'new@example.com')->firstOrFail();
    expect($user->canAccess($app))->toBeTrue();
});

it('forbids non-admins from the admin area', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('admin.users.index'))
        ->assertForbidden();
});

it('syncs a user\'s application access', function () {
    $admin = User::factory()->admin()->create();
    $tracker = Application::create(['name' => 'Tracker', 'slug' => 'tracker']);
    $zero = Application::create(['name' => 'Zero', 'slug' => 'zero']);

    $user = User::factory()->create();
    $user->applications()->attach($tracker);

    $this->actingAs($admin)
        ->put(route('admin.users.access.update', $user), ['applications' => [$zero->id]])
        ->assertSessionHasNoErrors();

    expect($user->canAccess($zero))->toBeTrue()
        ->and($user->canAccess($tracker))->toBeFalse();
});
