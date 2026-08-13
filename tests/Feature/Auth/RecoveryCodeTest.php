<?php

use App\Actions\Auth\GenerateRecoveryCodes;
use App\Actions\Auth\RedeemRecoveryCode;
use App\Models\RecoveryCode;
use App\Models\SignInEvent;
use App\Models\User;
use App\Notifications\RecoveryCodeUsed;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia;

beforeEach(fn () => confirmSession());

/**
 * @return array{0: User, 1: list<string>}
 */
function userWithCodes(): array
{
    $user = User::factory()->create();
    $codes = app(GenerateRecoveryCodes::class)->handle($user);

    return [$user, $codes];
}

it('signs in with a recovery code', function () {
    Notification::fake();
    [$user, $codes] = userWithCodes();

    $this->post(route('login.recovery-code'), ['email' => $user->email, 'code' => $codes[0]])
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
});

it('spends the code so it cannot be reused', function () {
    Notification::fake();
    [$user, $codes] = userWithCodes();

    $this->post(route('login.recovery-code'), ['email' => $user->email, 'code' => $codes[0]]);
    $this->post(route('logout'));

    $this->post(route('login.recovery-code'), ['email' => $user->email, 'code' => $codes[0]])
        ->assertSessionHasErrors('code');

    $this->assertGuest();
    expect(RecoveryCode::whereNotNull('used_at')->count())->toBe(1);
});

it('accepts a code however the separators are typed', function () {
    Notification::fake();
    [$user, $codes] = userWithCodes();

    $mangled = strtolower(str_replace('-', ' ', $codes[0]));

    $this->post(route('login.recovery-code'), ['email' => $user->email, 'code' => $mangled])
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
});

it('records the sign-in with its own method', function () {
    Notification::fake();
    [$user, $codes] = userWithCodes();

    $this->post(route('login.recovery-code'), ['email' => $user->email, 'code' => $codes[0]]);

    expect(SignInEvent::where('user_id', $user->id)->where('outcome', SignInEvent::SUCCESS)->value('method'))
        ->toBe('recovery_code');
});

it('emails the account when a code is used', function () {
    Notification::fake();
    [$user, $codes] = userWithCodes();

    $this->post(route('login.recovery-code'), ['email' => $user->email, 'code' => $codes[0]]);

    Notification::assertSentTo(
        $user,
        RecoveryCodeUsed::class,
        fn ($notification, $channels, $notifiable) => true,
    );
});

it('rejects a code belonging to another account', function () {
    Notification::fake();
    [, $codes] = userWithCodes();
    $other = User::factory()->create();

    $this->post(route('login.recovery-code'), ['email' => $other->email, 'code' => $codes[0]])
        ->assertSessionHasErrors('code');

    $this->assertGuest();
});

it('caps attempts per account regardless of source', function () {
    Notification::fake();
    [$user, $codes] = userWithCodes();

    // The per-minute route throttle is bypassed on purpose: this asserts the
    // per-account hourly cap, which is what a distributed attacker would face.
    $attempt = fn (string $code) => test()->withoutMiddleware(ThrottleRequests::class)
        ->post(route('login.recovery-code'), ['email' => $user->email, 'code' => $code]);

    foreach (range(1, RedeemRecoveryCode::MAX_ATTEMPTS_PER_HOUR) as $index) {
        $attempt('WRON-GCOD-EXXX');
    }

    expect(RateLimiter::tooManyAttempts('recovery-code:'.$user->id, RedeemRecoveryCode::MAX_ATTEMPTS_PER_HOUR))->toBeTrue();

    // Even the correct code is refused once the cap is reached.
    $attempt($codes[0])->assertSessionHasErrors('code');

    $this->assertGuest();
});

it('records a failed recovery attempt', function () {
    Notification::fake();
    [$user] = userWithCodes();

    $this->post(route('login.recovery-code'), ['email' => $user->email, 'code' => 'WRON-GCOD-EXXX']);

    expect(SignInEvent::where('user_id', $user->id)->where('outcome', SignInEvent::FAILURE)->value('method'))
        ->toBe('recovery_code');
});

it('issues codes on a first sign-in and shows them once', function () {
    Notification::fake();
    $user = User::factory()->create([
        'login_code_hash' => Hash::make('123456'),
        'login_code_expires_at' => now()->addMinutes(10),
    ]);

    expect($user->recoveryCodes()->count())->toBe(0);

    $this->post(route('login.code.verify'), ['email' => $user->email, 'code' => '123456']);

    expect($user->recoveryCodes()->count())->toBe(GenerateRecoveryCodes::COUNT);

    $this->get(route('security.edit'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('settings/Security')
            ->has('newRecoveryCodes', GenerateRecoveryCodes::COUNT)
        );

    $this->delete(route('recovery-codes.acknowledge'))->assertRedirect(route('security.edit'));

    $this->get(route('security.edit'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('newRecoveryCodes', null));
});

it('does not reissue codes on later sign-ins', function () {
    Notification::fake();
    [$user, $codes] = userWithCodes();

    $this->post(route('login.recovery-code'), ['email' => $user->email, 'code' => $codes[0]]);

    expect($user->recoveryCodes()->count())->toBe(GenerateRecoveryCodes::COUNT)
        ->and($user->recoveryCodes()->whereNull('used_at')->count())->toBe(GenerateRecoveryCodes::COUNT - 1);
});

it('replaces every code when regenerating', function () {
    Notification::fake();
    [$user, $codes] = userWithCodes();

    $this->actingAs($user)
        ->post(route('recovery-codes.regenerate'))
        ->assertRedirect(route('security.edit'));

    $this->post(route('logout'));

    $this->post(route('login.recovery-code'), ['email' => $user->email, 'code' => $codes[0]])
        ->assertSessionHasErrors('code');

    $this->assertGuest();
});

it('never stores a code in a form it could be read back from', function () {
    [, $codes] = userWithCodes();

    $stored = RecoveryCode::pluck('code_hash')->implode(' ');

    foreach ($codes as $code) {
        expect($stored)->not->toContain(str_replace('-', '', $code));
    }
});

it('keeps prompting until the codes are acknowledged', function () {
    Notification::fake();
    [$user] = userWithCodes();

    expect($user->fresh()->hasUnacknowledgedRecoveryCodes())->toBeTrue();

    $this->actingAs($user)
        ->get(route('security.edit'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('recoveryCodesUnsaved', true));
});

it('stops prompting once acknowledged', function () {
    Notification::fake();
    [$user] = userWithCodes();

    $this->actingAs($user)->delete(route('recovery-codes.acknowledge'))->assertRedirect();

    expect($user->fresh()->hasUnacknowledgedRecoveryCodes())->toBeFalse()
        ->and($user->fresh()->recovery_codes_acknowledged_at)->not->toBeNull();
});

it('does not count merely opening the page as acknowledgement', function () {
    Notification::fake();
    [$user] = userWithCodes();

    // The plaintext is gone the moment the session ends, so rendering the page
    // proves nothing about whether anyone wrote the codes down.
    $this->actingAs($user)->get(route('security.edit'))->assertOk();

    expect($user->fresh()->hasUnacknowledgedRecoveryCodes())->toBeTrue();
});

it('starts prompting again after regenerating', function () {
    Notification::fake();
    [$user] = userWithCodes();

    $this->actingAs($user)->delete(route('recovery-codes.acknowledge'));
    expect($user->fresh()->hasUnacknowledgedRecoveryCodes())->toBeFalse();

    $this->actingAs($user)->post(route('recovery-codes.regenerate'));

    expect($user->fresh()->hasUnacknowledgedRecoveryCodes())->toBeTrue();
});

it('shares the unsaved state so it can be surfaced anywhere', function () {
    Notification::fake();
    [$user] = userWithCodes();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('auth.unsavedRecoveryCodes', true));

    $this->actingAs($user)->delete(route('recovery-codes.acknowledge'));

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('auth.unsavedRecoveryCodes', false));
});

it('offers a replacement set when the plaintext was never saved', function () {
    Notification::fake();
    [$user, $codes] = userWithCodes();

    // Losing the session that held them is the case ID-58 exists for: the codes
    // are real, unusable, and the account looks protected.
    $this->actingAs($user)->post(route('recovery-codes.regenerate'))->assertRedirect();

    $this->post(route('logout'));

    $this->post(route('login.recovery-code'), ['email' => $user->email, 'code' => $codes[0]])
        ->assertSessionHasErrors('code');
});

it('does not prompt an account with no codes at all', function () {
    $user = User::factory()->create();

    expect($user->hasUnacknowledgedRecoveryCodes())->toBeFalse();
});

it('issues a usable set from the command line', function () {
    Notification::fake();
    $user = User::factory()->create();

    // The escape hatch for an account that cannot re-authenticate: without it,
    // regenerating codes is gated behind having a code.
    $this->artisan('id:recovery-codes', ['email' => $user->email])->assertSuccessful();

    expect($user->recoveryCodes()->whereNull('used_at')->count())->toBe(GenerateRecoveryCodes::COUNT)
        ->and($user->fresh()->hasUnacknowledgedRecoveryCodes())->toBeTrue();
});

it('invalidates the previous set from the command line', function () {
    Notification::fake();
    [$user, $codes] = userWithCodes();

    $this->artisan('id:recovery-codes', ['email' => $user->email])->assertSuccessful();

    $this->post(route('login.recovery-code'), ['email' => $user->email, 'code' => $codes[0]])
        ->assertSessionHasErrors('code');
});

it('refuses an unknown account', function () {
    $this->artisan('id:recovery-codes', ['email' => 'nobody@example.com'])->assertFailed();
});
