<?php

use App\Models\SignInEvent;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Notifications\NewDeviceSignIn;
use App\Services\DeviceFingerprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

beforeEach(fn () => confirmSession());

const TD_CHROME = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';
const TD_FIREFOX = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:129.0) Gecko/20100101 Firefox/129.0';

function tdSeedSignIn(User $user, string $agent, string $ip = '198.51.100.5'): void
{
    $fingerprints = new DeviceFingerprint;

    SignInEvent::create([
        'user_id' => $user->id,
        'method' => 'email_code',
        'outcome' => SignInEvent::SUCCESS,
        'ip_address' => $ip,
        'network' => $fingerprints->networkFor($ip),
        'user_agent' => $agent,
        'device_fingerprint' => $fingerprints->forUserAgent($agent),
    ]);
}

function tdLogin(User $user, string $agent, string $ip = '198.51.100.5')
{
    return test()
        ->withHeaders(['User-Agent' => $agent])
        ->withServerVariables(['REMOTE_ADDR' => $ip])
        ->post(route('login.code.verify'), ['email' => $user->email, 'code' => '123456']);
}

function tdTrust(User $user, string $agent, ?string $expiresAt = null): TrustedDevice
{
    return $user->trustedDevices()->create([
        'device_fingerprint' => (new DeviceFingerprint)->forUserAgent($agent),
        'label' => (new DeviceFingerprint)->label($agent),
        'expires_at' => $expiresAt ?? now()->addDays(TrustedDevice::TRUST_DAYS),
    ]);
}

function trustingUser(): User
{
    return User::factory()->create([
        'login_code_hash' => Hash::make('123456'),
        'login_code_expires_at' => now()->addMinutes(10),
    ]);
}

it('trusts the device making the request', function () {
    $user = trustingUser();

    $this->actingAs($user)
        ->withHeaders(['User-Agent' => TD_CHROME])
        ->post(route('trusted-devices.store'))
        ->assertRedirect();

    expect($user->fresh()->trusts((new DeviceFingerprint)->forUserAgent(TD_CHROME)))->toBeTrue();
});

it('stops alerting for a trusted device', function () {
    Notification::fake();
    $user = trustingUser();
    tdSeedSignIn($user, TD_CHROME);

    tdTrust($user, TD_FIREFOX);

    tdLogin($user, TD_FIREFOX);

    Notification::assertNotSentTo($user, NewDeviceSignIn::class);
});

it('still alerts a trusted device on a new network', function () {
    Notification::fake();
    $user = trustingUser();
    tdSeedSignIn($user, TD_CHROME, '198.51.100.5');

    tdTrust($user, TD_FIREFOX);

    // Travel and theft look different: the device being vouched for says
    // nothing about the network it turned up on.
    tdLogin($user, TD_FIREFOX, '203.0.113.9');

    Notification::assertSentToTimes($user, NewDeviceSignIn::class, 1);
});

it('alerts again once trust expires', function () {
    Notification::fake();
    $user = trustingUser();
    tdSeedSignIn($user, TD_CHROME);

    tdTrust($user, TD_FIREFOX, now()->subDay());

    tdLogin($user, TD_FIREFOX);

    Notification::assertSentToTimes($user, NewDeviceSignIn::class, 1);
});

it('revokes trust without signing the device out', function () {
    $user = trustingUser();

    $device = $user->trustedDevices()->create([
        'device_fingerprint' => 'abc',
        'label' => 'Chrome on macOS',
        'expires_at' => now()->addDays(TrustedDevice::TRUST_DAYS),
    ]);

    $this->actingAs($user)
        ->delete(route('trusted-devices.destroy', $device))
        ->assertRedirect();

    expect(TrustedDevice::find($device->id))->toBeNull();

    // Trust and sessions are different statements; revoking one must not end
    // the other.
    $this->actingAs($user)->get(route('dashboard'))->assertOk();
});

it('cannot revoke another user\'s trusted device', function () {
    $owner = trustingUser();
    $other = User::factory()->create();

    $device = $owner->trustedDevices()->create([
        'device_fingerprint' => 'abc',
        'label' => 'Chrome on macOS',
        'expires_at' => now()->addDays(TrustedDevice::TRUST_DAYS),
    ]);

    $this->actingAs($other)
        ->delete(route('trusted-devices.destroy', $device))
        ->assertForbidden();

    expect(TrustedDevice::find($device->id))->not->toBeNull();
});

it('prunes expired trust', function () {
    $user = trustingUser();

    $expired = $user->trustedDevices()->create([
        'device_fingerprint' => 'old',
        'label' => 'Old',
        'expires_at' => now()->subDay(),
    ]);
    $live = $user->trustedDevices()->create([
        'device_fingerprint' => 'live',
        'label' => 'Live',
        'expires_at' => now()->addDay(),
    ]);

    $this->artisan('model:prune', ['--model' => [TrustedDevice::class]])->assertSuccessful();

    expect(TrustedDevice::find($expired->id))->toBeNull()
        ->and(TrustedDevice::find($live->id))->not->toBeNull();
});
