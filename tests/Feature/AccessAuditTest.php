<?php

use App\Models\AccessAudit;
use App\Models\AccessRequest;
use App\Models\Application;
use App\Models\Group;
use App\Models\User;

function auditApp(string $slug): Application
{
    return Application::create(['name' => ucfirst($slug), 'slug' => $slug, 'active' => true]);
}

it('audits a direct grant, naming the acting admin', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $user = User::factory()->create();
    $app = auditApp('shop');

    $this->actingAs($admin)
        ->put(route('admin.users.access.update', $user), ['applications' => [$app->id]])
        ->assertRedirect();

    $audit = AccessAudit::where('action', 'grant')
        ->where('subject_user_id', $user->id)
        ->where('application_id', $app->id)
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->actor_id)->toBe($admin->id);
});

it('audits a revoke', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $user = User::factory()->create();
    $app = auditApp('shop');
    $user->applications()->attach($app);

    $this->actingAs($admin)
        ->put(route('admin.users.access.update', $user), ['applications' => []])
        ->assertRedirect();

    expect(AccessAudit::where('action', 'revoke')->where('subject_user_id', $user->id)->where('application_id', $app->id)->exists())->toBeTrue();
});

it('audits an approved access request', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $user = User::factory()->create();
    $app = auditApp('shop');
    $request = AccessRequest::create(['user_id' => $user->id, 'application_id' => $app->id, 'status' => 'pending']);

    $this->actingAs($admin)->post(route('admin.access-requests.approve', $request));

    expect(AccessAudit::where('action', 'grant')->where('subject_user_id', $user->id)->where('actor_id', $admin->id)->exists())->toBeTrue();
});

it('audits group membership and app-grant changes', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $user = User::factory()->create();
    $app = auditApp('shop');
    $group = Group::create(['name' => 'Team']);

    $this->actingAs($admin)
        ->put(route('admin.groups.update', $group), ['users' => [$user->id], 'applications' => [$app->id]])
        ->assertRedirect();

    expect(AccessAudit::where('action', 'group_member_add')->where('subject_user_id', $user->id)->exists())->toBeTrue()
        ->and(AccessAudit::where('action', 'group_app_grant')->where('application_id', $app->id)->exists())->toBeTrue();
});

it('filters the audit log by subject user', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $a = User::factory()->create();
    $b = User::factory()->create();
    $app = auditApp('shop');
    AccessAudit::create(['actor_id' => $admin->id, 'subject_user_id' => $a->id, 'application_id' => $app->id, 'action' => 'grant']);
    AccessAudit::create(['actor_id' => $admin->id, 'subject_user_id' => $b->id, 'application_id' => $app->id, 'action' => 'grant']);

    $this->actingAs($admin)
        ->get(route('admin.access-audit.index', ['user' => $a->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/AccessAudit')->has('audits', 1));
});

it('forbids non-admins from the audit log', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('admin.access-audit.index'))->assertForbidden();
});
