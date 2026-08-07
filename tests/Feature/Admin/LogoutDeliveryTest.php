<?php

use App\Models\Application;
use App\Models\LogoutNotification;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

function pendingDelivery(array $attributes = []): LogoutNotification
{
    $application = Application::firstOrCreate(
        ['slug' => 'zero'],
        ['name' => 'Zero', 'logout_secret' => Str::random(64), 'active' => true],
    );

    $notification = LogoutNotification::create([
        'user_id' => User::factory()->create()->id,
        'application_id' => $application->id,
        'endpoint' => 'https://zero.test/auth/sso/logout',
    ]);

    if ($attributes !== []) {
        $notification->forceFill($attributes)->save();
    }

    return $notification;
}

it('lists deliveries a consumer has not accepted', function () {
    $admin = User::factory()->admin()->create();
    pendingDelivery(['attempts' => 2, 'last_error' => 'HTTP 404']);

    $this->actingAs($admin)
        ->get(route('admin.logout-deliveries.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/LogoutDeliveries')
            ->has('pending', 1)
            ->where('pending.0.application', 'Zero')
            ->where('pending.0.attempts', 2)
            ->where('pending.0.last_error', 'HTTP 404')
            ->where('pending.0.abandoned', false)
        );
});

it('marks a delivery that gave up', function () {
    $admin = User::factory()->admin()->create();
    pendingDelivery(['attempts' => LogoutNotification::MAX_ATTEMPTS]);

    $this->actingAs($admin)
        ->get(route('admin.logout-deliveries.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('pending.0.abandoned', true));
});

it('leaves delivered ones out of the list', function () {
    $admin = User::factory()->admin()->create();
    pendingDelivery(['delivered_at' => now()]);

    $this->actingAs($admin)
        ->get(route('admin.logout-deliveries.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('pending', 0));
});

it('requeues an abandoned delivery so the schedule picks it up again', function () {
    Http::fake(['*' => Http::response('nope', 500)]);

    $admin = User::factory()->admin()->create();
    $notification = pendingDelivery(['attempts' => LogoutNotification::MAX_ATTEMPTS, 'last_error' => 'HTTP 404']);

    $this->actingAs($admin)
        ->post(route('admin.logout-deliveries.retry', $notification))
        ->assertRedirect();

    // The retry itself failed here, but the count restarted, so the scheduled
    // command will keep trying instead of ignoring it forever.
    expect($notification->fresh()?->attempts)->toBeLessThan(LogoutNotification::MAX_ATTEMPTS);
});

it('delivers on retry when the consumer is fixed', function () {
    Http::fake();

    $admin = User::factory()->admin()->create();
    $notification = pendingDelivery(['attempts' => LogoutNotification::MAX_ATTEMPTS]);

    $this->actingAs($admin)->post(route('admin.logout-deliveries.retry', $notification));

    expect($notification->fresh()?->delivered_at)->not->toBeNull();
});

it('does not resend one that already went through', function () {
    Http::fake();

    $admin = User::factory()->admin()->create();
    $notification = pendingDelivery(['delivered_at' => now()->subDay()]);

    $this->actingAs($admin)
        ->post(route('admin.logout-deliveries.retry', $notification))
        ->assertRedirect();

    Http::assertNothingSent();
});

it('keeps the view away from non-admins', function () {
    $notification = pendingDelivery();

    $this->actingAs(User::factory()->create())
        ->get(route('admin.logout-deliveries.index'))
        ->assertForbidden();

    $this->actingAs(User::factory()->create())
        ->post(route('admin.logout-deliveries.retry', $notification))
        ->assertForbidden();
});
