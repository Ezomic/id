<?php

declare(strict_types=1);

use App\Actions\Access\AutoGrantApplicationAccess;
use App\Models\AccessAudit;
use App\Models\Application;
use App\Models\Group;
use App\Models\User;

beforeEach(function () {
    $this->action = new AutoGrantApplicationAccess;
    // `active` is a database default, so a freshly created model has no such
    // attribute in memory until it is refreshed. Set it explicitly, which is
    // also what the real row looks like.
    $this->app_ = Application::create(['name' => 'Relay', 'slug' => 'relay', 'active' => true]);
});

/**
 * The case this exists for: `id:app` registers an application but attaches no
 * users, so the first sign-in into a brand new app used to fail outright.
 */
it('connects an admin to an application they have never used', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    expect($admin->canAccess($this->app_))->toBeFalse();
    expect($this->action->handle($admin, $this->app_))->toBeTrue();
    expect($admin->fresh()->canAccess($this->app_))->toBeTrue();
});

/**
 * The deliberate limit. Auto-granting everyone would mean a second, non-admin
 * account silently gaining access to every app the moment one is registered.
 */
it('does not connect a non-admin', function () {
    $user = User::factory()->create(['is_admin' => false]);

    expect($this->action->handle($user, $this->app_))->toBeFalse();
    expect($user->fresh()->canAccess($this->app_))->toBeFalse();
});

it('does not connect anyone to an inactive application', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $this->app_->forceFill(['active' => false])->save();

    expect($this->action->handle($admin, $this->app_->fresh()))->toBeFalse();
    expect($admin->fresh()->canAccess($this->app_))->toBeFalse();
});

it('is a no-op when access already exists, and does not duplicate the pivot', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $admin->applications()->attach($this->app_);

    expect($this->action->handle($admin, $this->app_))->toBeFalse();
    expect($admin->applications()->count())->toBe(1);
});

it('does not re-grant access that already comes from a group', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $group = Group::create(['name' => 'Staff']);
    $group->users()->attach($admin);
    $group->applications()->attach($this->app_);

    expect($admin->canAccess($this->app_))->toBeTrue();

    // Already reachable, so no direct pivot row is added on top.
    expect($this->action->handle($admin, $this->app_))->toBeFalse();
    expect($admin->applications()->count())->toBe(0);
});

it('audits the grant, because a self-service one still changes access', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    $this->action->handle($admin, $this->app_);

    $audit = AccessAudit::where('action', 'auto_grant')->firstOrFail();

    expect($audit->subject_user_id)->toBe($admin->id)
        ->and($audit->application_id)->toBe($this->app_->id);
});

it('lets an admin through userinfo on a first sign-in', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    expect($admin->canAccess($this->app_))->toBeFalse();

    $this->action->handle($admin, $this->app_);

    expect($admin->fresh()->accessibleApplicationIds()->all())->toContain($this->app_->id);
});
