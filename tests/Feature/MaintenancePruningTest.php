<?php

use App\Models\AccessAudit;
use App\Models\Application;
use App\Models\AuthorizedClient;
use App\Models\LogoutNotification;
use App\Models\SignInEvent;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

it('clears expired login codes and leaves live ones alone', function () {
    $expired = User::factory()->create([
        'login_code_hash' => Hash::make('111111'),
        'login_code_expires_at' => now()->subMinutes(30),
        'login_code_attempts' => 2,
    ]);

    $live = User::factory()->create([
        'login_code_hash' => Hash::make('222222'),
        'login_code_expires_at' => now()->addMinutes(5),
    ]);

    $this->artisan('id:prune-login-codes')->assertSuccessful();

    expect($expired->fresh()->login_code_hash)->toBeNull();
    expect($expired->fresh()->login_code_expires_at)->toBeNull();
    expect($expired->fresh()->login_code_attempts)->toBe(0);
    expect($live->fresh()->login_code_hash)->not->toBeNull();
});

it('prunes sign-in events past the retention window', function () {
    $user = User::factory()->create();

    $stale = SignInEvent::create([
        'user_id' => $user->id,
        'method' => 'email_code',
        'device_fingerprint' => 'old',
    ]);
    $stale->forceFill(['created_at' => now()->subDays(SignInEvent::RETENTION_DAYS + 1)])->save();

    $recent = SignInEvent::create([
        'user_id' => $user->id,
        'method' => 'email_code',
        'device_fingerprint' => 'new',
    ]);

    $this->artisan('model:prune', ['--model' => [SignInEvent::class]])->assertSuccessful();

    expect(SignInEvent::find($stale->id))->toBeNull();
    expect(SignInEvent::find($recent->id))->not->toBeNull();
});

it('schedules the maintenance commands', function () {
    $commands = collect(app(Schedule::class)->events())
        ->map(fn ($event) => $event->command ?? '')
        ->implode(' ');

    expect($commands)->toContain('passport:purge')
        ->toContain('model:prune')
        ->toContain('id:prune-login-codes');
});

it('prunes access audits past the retention window', function () {
    $user = User::factory()->create();

    $stale = AccessAudit::create(['subject_user_id' => $user->id, 'action' => 'grant']);
    $stale->forceFill(['created_at' => now()->subDays(AccessAudit::RETENTION_DAYS + 1)])->save();

    $recent = AccessAudit::create(['subject_user_id' => $user->id, 'action' => 'grant']);

    $this->artisan('model:prune', ['--model' => [AccessAudit::class]])->assertSuccessful();

    expect(AccessAudit::find($stale->id))->toBeNull()
        ->and(AccessAudit::find($recent->id))->not->toBeNull();
});

it('prunes abandoned sessions from authorized_clients but keeps active ones', function () {
    $user = User::factory()->create();

    $abandoned = AuthorizedClient::create([
        'user_id' => $user->id,
        'sso_session_id' => 'abandoned',
        'oauth_client_id' => Str::uuid()->toString(),
    ]);
    $abandoned->forceFill(['updated_at' => now()->subDays(AuthorizedClient::RETENTION_DAYS + 1)])->save();

    $active = AuthorizedClient::create([
        'user_id' => $user->id,
        'sso_session_id' => 'active',
        'oauth_client_id' => Str::uuid()->toString(),
    ]);

    $this->artisan('model:prune', ['--model' => [AuthorizedClient::class]])->assertSuccessful();

    expect(AuthorizedClient::find($abandoned->id))->toBeNull()
        ->and(AuthorizedClient::find($active->id))->not->toBeNull();
});

it('prunes delivered and abandoned logout notifications but never ones still owed', function () {
    $user = User::factory()->create();
    $application = Application::create(['name' => 'Zero', 'slug' => 'zero', 'active' => true]);

    $make = function (array $attributes) use ($user, $application) {
        $row = LogoutNotification::create([
            'user_id' => $user->id,
            'application_id' => $application->id,
            'endpoint' => 'https://zero.test/auth/sso/logout',
            ...$attributes,
        ]);
        $row->forceFill($attributes)->save();

        return $row;
    };

    $delivered = $make(['delivered_at' => now()->subDays(LogoutNotification::DELIVERED_RETENTION_DAYS + 1)]);
    $abandoned = $make(['attempts' => LogoutNotification::MAX_ATTEMPTS, 'updated_at' => now()->subDays(LogoutNotification::ABANDONED_RETENTION_DAYS + 1)]);

    // Old, failing, but not yet at the ceiling: still owed to the consumer.
    $stillOwed = $make(['attempts' => 1, 'updated_at' => now()->subYear()]);
    $freshlyDelivered = $make(['delivered_at' => now()]);

    $this->artisan('model:prune', ['--model' => [LogoutNotification::class]])->assertSuccessful();

    expect(LogoutNotification::find($delivered->id))->toBeNull()
        ->and(LogoutNotification::find($abandoned->id))->toBeNull()
        ->and(LogoutNotification::find($stillOwed->id))->not->toBeNull()
        ->and(LogoutNotification::find($freshlyDelivered->id))->not->toBeNull();
});

it('schedules all four prunable models', function () {
    $commands = collect(app(Schedule::class)->events())
        ->map(fn ($event) => $event->command ?? '')
        ->implode(' ');

    foreach ([SignInEvent::class, AccessAudit::class, AuthorizedClient::class, LogoutNotification::class] as $model) {
        expect($commands)->toContain(class_basename($model));
    }
});
