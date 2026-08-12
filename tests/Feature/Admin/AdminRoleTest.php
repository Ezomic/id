<?php

use App\Actions\Admin\SetAdminRole;
use App\Models\AccessAudit;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

it('warns while there is only one administrator', function () {
    $admin = User::factory()->admin()->create();
    User::factory()->create();

    $this->actingAs($admin)
        ->get(route('admin.users.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('adminCount', 1));
});

it('stops warning once a second administrator exists', function () {
    $admin = User::factory()->admin()->create();
    User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(route('admin.users.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('adminCount', 2));
});

it('promotes a user to administrator from the UI', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();

    $this->actingAs($admin)
        ->put(route('admin.users.role.update', $user))
        ->assertRedirect();

    expect($user->fresh()->is_admin)->toBeTrue()
        ->and(AccessAudit::where('action', 'admin_promote')->where('subject_user_id', $user->id)->exists())->toBeTrue();
});

it('refuses to demote the only administrator', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->put(route('admin.users.role.update', $admin))
        ->assertSessionHasErrors('is_admin');

    // Losing the last admin means nothing can grant access, register a client
    // or rotate a secret, with SSH as the only way back.
    expect($admin->fresh()->is_admin)->toBeTrue();
});

it('allows demotion once someone else is an administrator', function () {
    $admin = User::factory()->admin()->create();
    $second = User::factory()->admin()->create();

    $this->actingAs($second)
        ->put(route('admin.users.role.update', $admin))
        ->assertRedirect();

    expect($admin->fresh()->is_admin)->toBeFalse()
        ->and(AccessAudit::where('action', 'admin_demote')->exists())->toBeTrue();
});

it('refuses the last demotion at the action level too', function () {
    $admin = User::factory()->admin()->create();

    expect(fn () => app(SetAdminRole::class)->handle($admin, false))
        ->toThrow(RuntimeException::class);
});

it('keeps role changes away from non-admins', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    $this->actingAs($user)
        ->put(route('admin.users.role.update', $other))
        ->assertForbidden();
});
