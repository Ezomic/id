<?php

use App\Models\SignInEvent;
use App\Models\User;
use App\Notifications\NewDeviceSignIn;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;

function codeUser(): User
{
    return User::factory()->create([
        'login_code_hash' => Hash::make('123456'),
        'login_code_expires_at' => now()->addMinutes(10),
    ]);
}

function loginWithCode(User $user, string $agent = 'TestAgent')
{
    return test()
        ->withHeaders(['User-Agent' => $agent])
        ->post(route('login.code.verify'), ['email' => $user->email, 'code' => '123456']);
}

it('records a sign-in with its method and device', function () {
    Notification::fake();
    $user = codeUser();

    loginWithCode($user)->assertRedirect(route('dashboard'));

    $event = SignInEvent::where('user_id', $user->id)->first();
    expect($event)->not->toBeNull()
        ->and($event->method)->toBe('email_code')
        ->and($event->user_agent)->toBe('TestAgent')
        ->and($event->device_fingerprint)->toBe(hash('sha256', 'TestAgent'));
});

it('emails on a first-time device exactly once', function () {
    Notification::fake();
    $user = codeUser();

    loginWithCode($user);

    Notification::assertSentToTimes($user, NewDeviceSignIn::class, 1);
});

it('does not email for a device already seen', function () {
    Notification::fake();
    $user = codeUser();
    SignInEvent::create([
        'user_id' => $user->id,
        'method' => 'email_code',
        'ip_address' => '198.51.100.5',
        'user_agent' => 'TestAgent',
        'application' => null,
        'device_fingerprint' => hash('sha256', 'TestAgent'),
    ]);

    loginWithCode($user);

    Notification::assertNotSentTo($user, NewDeviceSignIn::class);
});

it('shows the user their own sign-in history', function () {
    $user = codeUser();
    $other = User::factory()->create();
    SignInEvent::create(['user_id' => $user->id, 'method' => 'passkey', 'ip_address' => '1.1.1.1', 'user_agent' => 'A', 'device_fingerprint' => hash('sha256', 'A')]);
    SignInEvent::create(['user_id' => $other->id, 'method' => 'passkey', 'ip_address' => '2.2.2.2', 'user_agent' => 'B', 'device_fingerprint' => hash('sha256', 'B')]);

    $this->actingAs($user)
        ->get(route('sign-in-history.edit'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('settings/SignInHistory')
            ->has('events', 1)
            ->where('events.0.method', 'passkey')
        );
});
