<?php

use App\Models\SignInEvent;
use App\Models\User;
use App\Notifications\NewDeviceSignIn;
use App\Services\DeviceFingerprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;

const CHROME_MAC = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';
const CHROME_MAC_UPDATED = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.7100.4 Safari/537.36';
const FIREFOX_WINDOWS = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:129.0) Gecko/20100101 Firefox/129.0';

function codeUser(): User
{
    return User::factory()->create([
        'login_code_hash' => Hash::make('123456'),
        'login_code_expires_at' => now()->addMinutes(10),
    ]);
}

function loginWithCode(User $user, string $agent = CHROME_MAC, string $ip = '198.51.100.5')
{
    return test()
        ->withHeaders(['User-Agent' => $agent])
        ->withServerVariables(['REMOTE_ADDR' => $ip])
        ->post(route('login.code.verify'), ['email' => $user->email, 'code' => '123456']);
}

function seedSignIn(User $user, string $agent = CHROME_MAC, string $ip = '198.51.100.5'): SignInEvent
{
    $fingerprints = new DeviceFingerprint;

    return SignInEvent::create([
        'user_id' => $user->id,
        'method' => 'email_code',
        'ip_address' => $ip,
        'network' => $fingerprints->networkFor($ip),
        'user_agent' => $agent,
        'device_fingerprint' => $fingerprints->forUserAgent($agent),
    ]);
}

it('records a sign-in with its method, device and network', function () {
    Notification::fake();
    $user = codeUser();

    loginWithCode($user)->assertRedirect(route('dashboard'));

    $event = SignInEvent::where('user_id', $user->id)->first();

    expect($event)->not->toBeNull()
        ->and($event->method)->toBe('email_code')
        ->and($event->user_agent)->toBe(CHROME_MAC)
        ->and($event->network)->toBe('198.51.100.0/24')
        ->and($event->device_fingerprint)->toBe(hash('sha256', 'Chrome on macOS'));
});

it('does not email on the very first sign-in', function () {
    Notification::fake();
    $user = codeUser();

    loginWithCode($user);

    Notification::assertNotSentTo($user, NewDeviceSignIn::class);
});

it('does not email for a device and network already seen', function () {
    Notification::fake();
    $user = codeUser();
    seedSignIn($user);

    loginWithCode($user);

    Notification::assertNotSentTo($user, NewDeviceSignIn::class);
});

it('does not treat a browser version bump as a new device', function () {
    Notification::fake();
    $user = codeUser();
    seedSignIn($user, CHROME_MAC);

    loginWithCode($user, CHROME_MAC_UPDATED);

    Notification::assertNotSentTo($user, NewDeviceSignIn::class);
});

it('emails when a genuinely different device signs in', function () {
    Notification::fake();
    $user = codeUser();
    seedSignIn($user, CHROME_MAC);

    loginWithCode($user, FIREFOX_WINDOWS);

    Notification::assertSentToTimes($user, NewDeviceSignIn::class, 1);
});

it('emails when a known device appears on a new network', function () {
    Notification::fake();
    $user = codeUser();
    seedSignIn($user, CHROME_MAC, '198.51.100.5');

    loginWithCode($user, CHROME_MAC, '203.0.113.9');

    Notification::assertSentToTimes($user, NewDeviceSignIn::class, 1);
});

it('shows the user their own sign-in history', function () {
    $user = codeUser();
    $other = User::factory()->create();
    seedSignIn($user, CHROME_MAC);
    seedSignIn($other, FIREFOX_WINDOWS);

    $this->actingAs($user)
        ->get(route('sign-in-history.edit'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('settings/SignInHistory')
            ->has('events', 1)
            ->where('events.0.device', 'Chrome on macOS')
        );
});

it('groups addresses into networks rather than comparing them exactly', function () {
    $fingerprints = new DeviceFingerprint;

    expect($fingerprints->networkFor('198.51.100.5'))->toBe('198.51.100.0/24')
        ->and($fingerprints->networkFor('198.51.100.200'))->toBe('198.51.100.0/24')
        ->and($fingerprints->networkFor('2001:db8:1234:5678::1'))->toBe('2001:db8:1234::/48')
        ->and($fingerprints->networkFor(null))->toBeNull();
});

it('falls back to the raw agent when nothing is recognisable', function () {
    $fingerprints = new DeviceFingerprint;

    expect($fingerprints->label('curl/8.7.1'))->toBe('curl/8.7.1')
        ->and($fingerprints->label(null))->toBe('Unknown device')
        ->and($fingerprints->label(FIREFOX_WINDOWS))->toBe('Firefox on Windows');
});
