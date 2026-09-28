<?php

declare(strict_types=1);

use App\Actions\Admin\InviteUser;
use App\Actions\Auth\GenerateRecoveryCodes;
use App\Models\SignInEvent;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Passport;

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

it('keeps the other browsers remembered when one browser signs out', function () {
    $user = User::factory()->create();
    $phone = rememberedSignIn($user);

    $laptop = rememberedSignIn($user);
    $this->withCookie(recallerName(), $laptop)
        ->post(route('logout'))
        ->assertRedirect()
        ->assertCookieExpired(recallerName());
    app('cookie')->flushQueuedCookies();

    // What the laptop has left once its browser drops the expired cookie: a
    // session that no longer knows the user.
    app('auth')->forgetGuards();
    $this->withCookie(recallerName(), '')->get(route('dashboard'))->assertRedirect(route('login'));
    $this->assertGuest();

    expectRestored($user, returnWithCookie($phone));
});

it('still ends a browser that outlived a sign-out elsewhere when the user is signed out everywhere', function () {
    $user = User::factory()->create();
    $phone = rememberedSignIn($user);

    $laptop = rememberedSignIn($user);
    $this->withCookie(recallerName(), $laptop)->post(route('logout'))->assertRedirect();
    app('cookie')->flushQueuedCookies();
    expectRestored($user, returnWithCookie($phone));

    sessionExpires();
    confirmSession();
    $this->actingAs(User::factory()->admin()->create())
        ->post(route('admin.users.sign-out', $user))
        ->assertRedirect();

    returnWithCookie($phone)->assertRedirect(route('login'));
    $this->assertGuest();
});

it('ends remembered browsers when an admin signs the user out everywhere', function () {
    $user = User::factory()->create();
    $cookie = rememberedSignIn($user);

    sessionExpires();
    confirmSession();
    $this->actingAs(User::factory()->admin()->create())
        ->post(route('admin.users.sign-out', $user))
        ->assertRedirect();

    returnWithCookie($cookie)->assertRedirect(route('login'));
    $this->assertGuest();
});

it('ends ID\'s own sessions and remembered browsers when an app signs the user out of the estate', function () {
    $user = User::factory()->create();
    $cookie = rememberedSignIn($user);
    DB::table('sessions')->insert([
        'id' => 'laptop-session',
        'user_id' => $user->id,
        'ip_address' => '203.0.113.10',
        'user_agent' => 'Mozilla/5.0',
        'payload' => 'x',
        'last_activity' => time(),
    ]);

    sessionExpires();
    Passport::actingAs($user);
    $this->postJson('/api/sso/logout')->assertOk();

    // auth:api left api as the default guard for the rest of this test, and a
    // browser coming back is looked up through web.
    app('auth')->setDefaultDriver('web');

    expect(DB::table('sessions')->where('user_id', $user->id)->exists())->toBeFalse();
    returnWithCookie($cookie)->assertRedirect(route('login'));
    $this->assertGuest();
});

it('ends the revoked browser but keeps the one revoking it remembered', function () {
    $user = User::factory()->create();
    $phone = rememberedSignIn($user);
    DB::table('sessions')->insert([
        'id' => 'phone-session',
        'user_id' => $user->id,
        'ip_address' => '203.0.113.10',
        'user_agent' => 'Mozilla/5.0',
        'payload' => 'x',
        'last_activity' => time(),
    ]);

    $laptop = rememberedSignIn($user);
    $response = $this->withCookie(recallerName(), $laptop)
        ->delete(route('sessions.destroy', ['id' => 'phone-session']))
        ->assertRedirect();
    $this->assertAuthenticatedAs($user);
    $renewed = rememberCookieFrom($response);

    returnWithCookie($phone)->assertRedirect(route('login'));
    $this->assertGuest();

    expectRestored($user, returnWithCookie($renewed));
});

it('ends the other browsers but keeps the one signing them out remembered', function () {
    $user = User::factory()->create();
    $phone = rememberedSignIn($user);

    $laptop = rememberedSignIn($user);
    $response = $this->withCookie(recallerName(), $laptop)
        ->delete(route('sessions.destroyOthers'))
        ->assertRedirect();
    $this->assertAuthenticatedAs($user);
    $renewed = rememberCookieFrom($response);

    returnWithCookie($phone)->assertRedirect(route('login'));
    $this->assertGuest();

    expectRestored($user, returnWithCookie($renewed));
});

it('does not start remembering a browser that signed in without it', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->delete(route('sessions.destroyOthers'))
        ->assertRedirect()
        ->assertCookieMissing(recallerName());
});
