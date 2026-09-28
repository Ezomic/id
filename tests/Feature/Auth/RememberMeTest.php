<?php

declare(strict_types=1);

use App\Actions\Admin\InviteUser;
use App\Actions\Auth\GenerateRecoveryCodes;
use App\Models\SignInEvent;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

function recallerName(): string
{
    return Auth::guard('web')->getRecallerName();
}

/**
 * Also empties the cookie queue. The test app outlives a request, so without
 * that every later response would carry this cookie again, as if it had just
 * been issued.
 */
function rememberCookieFrom(TestResponse $response): string
{
    $cookie = $response->getCookie(recallerName());
    app('cookie')->flushQueuedCookies();

    expect($cookie)->not->toBeNull();

    return (string) $cookie?->getValue();
}

/**
 * What a remembered browser has left once its 120-minute session is gone:
 * nothing but the cookie.
 */
function sessionExpires(): void
{
    test()->flushSession();
    app('auth')->forgetGuards();
}

function returnWithCookie(string $cookie): TestResponse
{
    sessionExpires();

    return test()->withCookie(recallerName(), $cookie)->get(route('dashboard'));
}

/**
 * A fresh browser signing in with an emailed code, which is how nearly every
 * sign-in at ID happens. Returns the remember-me cookie it was given.
 */
function rememberedSignIn(User $user): string
{
    sessionExpires();

    // A previous sign-in spent the code behind this instance's back, so an
    // unchanged expiry would not be written again.
    $user->refresh()->forceFill([
        'login_code_hash' => Hash::make('123456'),
        'login_code_expires_at' => now()->addMinutes(10),
    ])->save();

    return rememberCookieFrom(
        test()->post(route('login.code.verify'), ['email' => $user->email, 'code' => '123456']),
    );
}

function expectRestored(User $user, TestResponse $response): void
{
    $response->assertOk();
    test()->assertAuthenticatedAs($user);

    // RecordSignIn files a restore under 'other', since it happens on
    // whatever page the browser asked for rather than on a login route.
    expect(SignInEvent::where('user_id', $user->id)->where('method', 'other')->exists())->toBeTrue();
}

it('restores the session from the remember cookie after an email code sign-in', function () {
    $user = User::factory()->create();

    $cookie = rememberedSignIn($user);

    expectRestored($user, returnWithCookie($cookie));
});

it('restores the session from the remember cookie after a recovery code sign-in', function () {
    Notification::fake();
    $user = User::factory()->create();
    $codes = app(GenerateRecoveryCodes::class)->handle($user);

    $cookie = rememberCookieFrom(
        $this->post(route('login.recovery-code'), ['email' => $user->email, 'code' => $codes[0]]),
    );

    expectRestored($user, returnWithCookie($cookie));
});

it('restores the session from the remember cookie after accepting an invitation', function () {
    Notification::fake();
    $invitee = User::factory()->create();
    $token = app(InviteUser::class)->handle($invitee, User::factory()->admin()->create());

    $cookie = rememberCookieFrom($this->get(route('invitations.accept', ['token' => $token])));

    expectRestored($invitee, returnWithCookie($cookie));
});

it('honours a cookie hashed from the null password', function () {
    $user = User::factory()->create(['remember_token' => Str::random(60)]);

    // How SessionGuard::queueRecallerCookie() built every cookie ID has handed
    // out: getAuthPassword() returned null, and the HMAC took it as ''.
    $cookie = $user->id.'|'.$user->remember_token.'|'.Auth::guard('web')->hashPasswordForCookie(null);

    expectRestored($user, returnWithCookie($cookie));
});
