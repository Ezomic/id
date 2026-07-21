<?php

use App\Models\Application;
use App\Models\Group;
use App\Models\User;

function app_(string $slug): Application
{
    return Application::create(['name' => ucfirst($slug), 'slug' => $slug, 'active' => true]);
}

it('grants access to an app through group membership', function () {
    $user = User::factory()->create();
    $app = app_('shop');
    $group = Group::create(['name' => 'Team']);
    $group->applications()->attach($app);
    $group->users()->attach($user);

    expect($user->fresh()->canAccess($app))->toBeTrue()
        ->and($user->fresh()->accessibleApplicationIds()->contains($app->id))->toBeTrue();
});

it('revokes group-granted access when the user leaves the group', function () {
    $user = User::factory()->create();
    $app = app_('shop');
    $group = Group::create(['name' => 'Team']);
    $group->applications()->attach($app);
    $group->users()->attach($user);

    $group->users()->detach($user);

    expect($user->fresh()->canAccess($app))->toBeFalse();
});

it('keeps access after leaving a group when a direct grant exists', function () {
    $user = User::factory()->create();
    $app = app_('shop');
    $group = Group::create(['name' => 'Team']);
    $group->applications()->attach($app);
    $group->users()->attach($user);
    $user->applications()->attach($app); // direct grant too

    $group->users()->detach($user);

    expect($user->fresh()->canAccess($app))->toBeTrue();
});

it('unions direct and group grants without duplicates', function () {
    $user = User::factory()->create();
    $direct = app_('finance');
    $viaGroup = app_('shop');
    $shared = app_('zero');

    $user->applications()->attach([$direct->id, $shared->id]);
    $group = Group::create(['name' => 'Team']);
    $group->applications()->attach([$viaGroup->id, $shared->id]);
    $group->users()->attach($user);

    $ids = $user->fresh()->accessibleApplicationIds()->sort()->values()->all();

    expect($ids)->toBe(collect([$direct->id, $viaGroup->id, $shared->id])->sort()->values()->all());
});

it('lets an admin create a group and grant access through it', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $user = User::factory()->create();
    $app = app_('shop');

    $this->actingAs($admin)->post(route('admin.groups.store'), ['name' => 'Team'])->assertRedirect();
    $group = Group::firstWhere('name', 'Team');
    expect($group)->not->toBeNull();

    $this->actingAs($admin)
        ->put(route('admin.groups.update', $group), ['users' => [$user->id], 'applications' => [$app->id]])
        ->assertRedirect();

    expect($user->fresh()->canAccess($app))->toBeTrue();
});

it('forbids non-admins from managing groups', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('admin.groups.index'))->assertForbidden();
});
