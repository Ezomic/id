<?php

use App\Actions\Admin\CreateApplication;
use App\Models\Application;
use App\Models\User;

it('lets an admin register an application and reveals credentials once', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('admin.applications.store'), [
            'name' => 'Chronos',
            'slug' => 'chronos',
            'description' => 'Calendar across the suite',
            'initials' => 'K',
            'accent' => '#6366F1',
            'launch_url' => 'https://chronos.test',
            'redirect_uri' => 'https://chronos.test/auth/callback',
        ])
        ->assertSessionHasNoErrors();

    $app = Application::where('slug', 'chronos')->firstOrFail();

    expect($app->oauth_client_id)->not->toBeNull()
        ->and($app->redirectUri())->toBe('https://chronos.test/auth/callback');

    $created = session('createdClient');

    expect($created['client_id'])->toBe($app->oauth_client_id)
        ->and($created['client_secret'])->not->toBeEmpty();
});

it('rejects a duplicate slug when registering', function () {
    $admin = User::factory()->admin()->create();
    Application::create(['name' => 'Zero', 'slug' => 'zero']);

    $this->actingAs($admin)
        ->post(route('admin.applications.store'), [
            'name' => 'Zero Two',
            'slug' => 'zero',
            'redirect_uri' => 'https://zero.test/auth/callback',
        ])
        ->assertSessionHasErrors('slug');
});

it('forbids non-admins from the applications console', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('admin.applications.index'))
        ->assertForbidden();
});

it('updates an application and syncs who can access it', function () {
    $admin = User::factory()->admin()->create();
    $member = User::factory()->create();

    $app = app(CreateApplication::class)->handle([
        'name' => 'Billr',
        'slug' => 'billr',
        'redirect_uri' => 'https://billr.test/auth/callback',
    ])['application'];

    $this->actingAs($admin)
        ->put(route('admin.applications.update', $app), [
            'name' => 'Billr',
            'slug' => 'billr',
            'description' => 'Invoicing & billing',
            'initials' => 'B',
            'accent' => '#E0A83E',
            'launch_url' => 'https://billr.test',
            'redirect_uri' => 'https://billr.test/auth/callback-v2',
            'active' => true,
            'users' => [$member->id],
        ])
        ->assertSessionHasNoErrors();

    $app->refresh();

    expect($app->description)->toBe('Invoicing & billing')
        ->and($app->redirectUri())->toBe('https://billr.test/auth/callback-v2')
        ->and($member->canAccess($app))->toBeTrue();
});
