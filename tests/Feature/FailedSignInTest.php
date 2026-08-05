<?php

use App\Actions\Auth\RecordFailedSignIn;
use App\Models\SignInEvent;
use App\Models\User;
use App\Notifications\SuspiciousSignInAttempts;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;

function failingUser(): User
{
    return User::factory()->create([
        'login_code_hash' => Hash::make('123456'),
        'login_code_expires_at' => now()->addMinutes(10),
    ]);
}

it('records a failure when the code is wrong', function () {
    Notification::fake();
    $user = failingUser();

    $this->post(route('login.code.verify'), ['email' => $user->email, 'code' => '000000'])
        ->assertSessionHasErrors('code');

    $event = SignInEvent::where('user_id', $user->id)->first();

    expect($event)->not->toBeNull()
        ->and($event->outcome)->toBe(SignInEvent::FAILURE)
        ->and($event->method)->toBe('email_code');
});

it('records a code request for an address with no account without a user row', function () {
    Notification::fake();

    $this->post(route('login.code.send'), ['email' => 'nobody@example.com'])
        ->assertRedirect(route('login'));

    $event = SignInEvent::first();

    expect($event)->not->toBeNull()
        ->and($event->outcome)->toBe(SignInEvent::FAILURE)
        ->and($event->user_id)->toBeNull()
        ->and(User::count())->toBe(0);
});

it('stores nothing that identifies the attempted address', function () {
    Notification::fake();

    $this->post(route('login.code.send'), ['email' => 'secret-person@example.com']);

    $stored = json_encode(SignInEvent::first()?->getAttributes());

    expect($stored)->not->toContain('secret-person');
});

it('emails once when failures cross the burst threshold', function () {
    Notification::fake();
    $user = failingUser();

    foreach (range(1, RecordFailedSignIn::BURST_THRESHOLD + 3) as $attempt) {
        $this->post(route('login.code.verify'), ['email' => $user->email, 'code' => '000000']);
    }

    Notification::assertSentToTimes($user, SuspiciousSignInAttempts::class, 1);
});

it('does not email below the burst threshold', function () {
    Notification::fake();
    $user = failingUser();

    foreach (range(1, RecordFailedSignIn::BURST_THRESHOLD - 1) as $attempt) {
        $this->post(route('login.code.verify'), ['email' => $user->email, 'code' => '000000']);
    }

    Notification::assertNotSentTo($user, SuspiciousSignInAttempts::class);
});

it('shows failures alongside successes in the user history', function () {
    Notification::fake();
    $user = failingUser();

    app(RecordFailedSignIn::class)->handle($user, 'email_code');

    $this->actingAs($user)
        ->get(route('sign-in-history.edit'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('settings/SignInHistory')
            ->where('events.0.outcome', SignInEvent::FAILURE)
        );
});

it('does not count a failure as a known device for new-device alerts', function () {
    Notification::fake();
    $user = failingUser();

    // A failed attempt from an attacker's browser must not teach the system to
    // treat that browser as familiar.
    app(RecordFailedSignIn::class)->handle($user, 'email_code');

    expect(SignInEvent::where('user_id', $user->id)->where('outcome', SignInEvent::SUCCESS)->count())->toBe(0);
});

it('lets an admin review failed attempts', function () {
    Notification::fake();
    $admin = User::factory()->admin()->create();
    $user = failingUser();

    app(RecordFailedSignIn::class)->handle($user, 'email_code');
    app(RecordFailedSignIn::class)->handle(null, 'passkey');

    $this->actingAs($admin)
        ->get(route('admin.failed-sign-ins.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/FailedSignIns')
            ->has('attempts', 2)
            ->where('attempts.0.account', null)
            ->where('attempts.1.account', $user->email)
        );
});

it('keeps failed attempts away from non-admins', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('admin.failed-sign-ins.index'))
        ->assertForbidden();
});
