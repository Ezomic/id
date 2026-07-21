<?php

use App\Models\AccessRequest;
use App\Models\Application;
use App\Models\User;
use App\Notifications\AccessRequestDecided;
use Illuminate\Support\Facades\Notification;

function lockedApp(): Application
{
    return Application::create(['name' => 'Shop', 'slug' => 'shop', 'active' => true]);
}

it('creates a pending request for a locked app', function () {
    $user = User::factory()->create();
    $app = lockedApp();

    $this->actingAs($user)
        ->post(route('access-requests.store'), ['application_id' => $app->id])
        ->assertRedirect();

    expect(AccessRequest::where('user_id', $user->id)->where('application_id', $app->id)->pending()->count())->toBe(1);
});

it('deduplicates repeat requests', function () {
    $user = User::factory()->create();
    $app = lockedApp();

    $this->actingAs($user)->post(route('access-requests.store'), ['application_id' => $app->id]);
    $this->actingAs($user)->post(route('access-requests.store'), ['application_id' => $app->id]);

    expect(AccessRequest::where('user_id', $user->id)->pending()->count())->toBe(1);
});

it('does not create a request when you already have access', function () {
    $user = User::factory()->create();
    $app = lockedApp();
    $user->applications()->attach($app);

    $this->actingAs($user)->post(route('access-requests.store'), ['application_id' => $app->id]);

    expect(AccessRequest::count())->toBe(0);
});

it('lets an admin approve, granting access and notifying the requester', function () {
    Notification::fake();
    $admin = User::factory()->create(['is_admin' => true]);
    $user = User::factory()->create();
    $app = lockedApp();
    $request = AccessRequest::create(['user_id' => $user->id, 'application_id' => $app->id, 'status' => 'pending']);

    $this->actingAs($admin)
        ->post(route('admin.access-requests.approve', $request))
        ->assertRedirect();

    expect($user->fresh()->applications()->whereKey($app->id)->exists())->toBeTrue()
        ->and($request->fresh()->status)->toBe('approved')
        ->and($request->fresh()->decided_by_id)->toBe($admin->id);
    Notification::assertSentTo($user, AccessRequestDecided::class);
});

it('lets an admin deny without changing access', function () {
    Notification::fake();
    $admin = User::factory()->create(['is_admin' => true]);
    $user = User::factory()->create();
    $app = lockedApp();
    $request = AccessRequest::create(['user_id' => $user->id, 'application_id' => $app->id, 'status' => 'pending']);

    $this->actingAs($admin)
        ->post(route('admin.access-requests.deny', $request))
        ->assertRedirect();

    expect($user->fresh()->applications()->whereKey($app->id)->exists())->toBeFalse()
        ->and($request->fresh()->status)->toBe('denied');
    Notification::assertSentTo($user, AccessRequestDecided::class);
});

it('forbids a non-admin from the request queue', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('admin.access-requests.index'))->assertForbidden();
});
