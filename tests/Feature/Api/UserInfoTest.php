<?php

use App\Models\Application;
use App\Models\User;

it('requires authentication', function () {
    $this->getJson('/api/userinfo')->assertUnauthorized();
});

it('knows whether a user may access an application', function () {
    $app = Application::create(['name' => 'Zero', 'slug' => 'zero']);
    $user = User::factory()->create();

    expect($user->canAccess($app))->toBeFalse();

    $user->applications()->attach($app);

    expect($user->fresh()->canAccess($app))->toBeTrue();
});
