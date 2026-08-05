<?php

use App\Models\SignInEvent;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Hash;

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
