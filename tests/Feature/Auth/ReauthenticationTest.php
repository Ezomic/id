<?php

declare(strict_types=1);

use App\Actions\Auth\GenerateRecoveryCodes;
use App\Http\Middleware\RequireRecentAuthentication;
use App\Models\AccessAudit;
use App\Models\Application;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;

function confirmedAdmin(): User
{
    $admin = User::factory()->admin()->create();
    test()->actingAs($admin);
    session(['auth.password_confirmed_at' => Date::now()->unix()]);

    return $admin;
}

/**
 * An admin who can confirm with $factor, and the confirmation as the browser
 * performs it, returning the URL the browser is sent on to afterwards.
 *
 * @return array{0: User, 1: Closure(): string}
 */
function stepUpAdmin(string $factor): array
{
    if ($factor === 'passkey') {
        [$admin, $key] = passkeyHolder();
        $admin->update(['is_admin' => true]);

        return [$admin, fn (): string => passkeyCeremony($admin, $key, route('passkey.confirm-options'), route('passkey.confirm'))
            ->assertOk()
            ->json('redirect')];
    }

    $admin = User::factory()->admin()->create();
    [$code] = app(GenerateRecoveryCodes::class)->handle($admin);

    return [$admin, fn (): string => test()->post(route('reauthenticate.confirm'), ['code' => $code])
        ->assertRedirect()
        ->headers->get('Location')];
}

/**
 * A guarded action sent the way an Inertia visit sends it: an XHR, which
 * Laravel never records as the previous URL, carrying the page it was made
 * from as the Referer unless the browser withholds it.
 */
function inertiaVisit(string $method, string $url, ?string $referer): TestResponse
{
    return test()->call($method, $url, server: array_filter([
        'HTTP_X_INERTIA' => 'true',
        'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        'HTTP_REFERER' => $referer,
    ]));
}

dataset('factors', ['recovery code', 'passkey']);

// Every guarded action as [method, action URL, the page it is taken from].
dataset('guarded actions', [
    'role change' => [fn () => ['PUT', route('admin.users.role.update', User::factory()->create()), route('admin.users.index')]],
    'admin sign-out' => [function () {
        $user = User::factory()->create();

        return ['POST', route('admin.users.sign-out', $user), route('admin.users.show', $user)];
    }],
    'application update' => [fn () => ['PUT', route('admin.applications.update', Application::create(['name' => 'Zero', 'slug' => 'zero', 'active' => true])), route('admin.applications.index')]],
    'secret rotation' => [fn () => ['POST', route('admin.applications.rotate-secret', Application::create(['name' => 'Zero', 'slug' => 'zero', 'active' => true])), route('admin.applications.index')]],
    'recovery code regeneration' => [fn () => ['POST', route('recovery-codes.regenerate'), route('security.edit')]],
    'account deletion' => [fn () => ['DELETE', route('profile.destroy'), route('profile.edit')]],
]);

// Referers the step-up must not send anyone on to. Built from this app's own
// origin where the trick depends on it, so they hold whatever APP_URL is.
dataset('foreign referers', [
    'another origin' => ['https://evil.example/admin/users'],
    'a protocol-relative URL' => ['//evil.example/admin/users'],
    'a backslash for the second slash' => ['/\\evil.example/admin/users'],
    'another scheme' => [fn () => (request()->isSecure() ? 'http://' : 'https://').request()->getHttpHost().'/admin/users'],
    'another port' => [fn () => request()->getScheme().'://'.request()->getHost().':8443/admin/users'],
    'this host as the start of another' => [fn () => request()->getSchemeAndHttpHost().'.evil.example/admin/users'],
    'this host as userinfo for another' => [fn () => request()->getSchemeAndHttpHost().'@evil.example/admin/users'],
    'a backslash ending another host early' => [fn () => 'https://evil.example\\@'.request()->getHttpHost().'/admin/users'],
    'a javascript: URL' => ['javascript:alert(document.domain)'],
    'a data: URL' => ['data:text/html,<script>alert(document.domain)</script>'],
    'a path that only takes a POST' => [fn () => route('admin.users.sign-out', User::factory()->create())],
    'a path with no page' => [fn () => url('/no-such-page')],
]);

it('sends an unconfirmed session to re-authenticate', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();

    $this->actingAs($admin)
        ->post(route('admin.users.sign-out', $user))
        ->assertRedirect(route('reauthenticate.show'));
});

it('lets a freshly confirmed session through', function () {
    $admin = confirmedAdmin();
    $user = User::factory()->create();

    $this->post(route('admin.users.sign-out', $user))->assertRedirect();
    expect($admin)->not->toBeNull();
});

it('expires the confirmation', function () {
    confirmedAdmin();
    $user = User::factory()->create();

    $this->travel(RequireRecentAuthentication::WINDOW_MINUTES + 1)->minutes();

    $this->post(route('admin.users.sign-out', $user))
        ->assertRedirect(route('reauthenticate.show'));
});

it('confirms with a recovery code', function () {
    $user = User::factory()->create();
    $codes = app(GenerateRecoveryCodes::class)->handle($user);

    $this->actingAs($user)
        ->post(route('reauthenticate.confirm'), ['code' => $codes[0]])
        ->assertRedirect();

    expect(session('auth.password_confirmed_at'))->not->toBeNull()
        ->and(AccessAudit::where('action', 'reauthenticated')->exists())->toBeTrue();
});

it('refuses a wrong recovery code', function () {
    $user = User::factory()->create();
    app(GenerateRecoveryCodes::class)->handle($user);

    $this->actingAs($user)
        ->post(route('reauthenticate.confirm'), ['code' => 'WRON-GCOD-EXXX'])
        ->assertSessionHasErrors('code');

    expect(session('auth.password_confirmed_at'))->toBeNull();
});

it('returns to the page that triggered the prompt', function () {
    $admin = User::factory()->admin()->create();
    $codes = app(GenerateRecoveryCodes::class)->handle($admin);
    $user = User::factory()->create();

    $this->actingAs($admin)
        ->post(route('admin.users.sign-out', $user), headers: ['Referer' => route('admin.users.show', $user)]);

    $this->post(route('reauthenticate.confirm'), ['code' => $codes[0]])
        ->assertRedirect(route('admin.users.show', $user));
});

it('lands a confirmed admin on the page the action was taken from, ready to repeat it', function (array $action, string $factor) {
    [$method, $url, $page] = $action;
    [$admin, $confirm] = stepUpAdmin($factor);

    $this->actingAs($admin);
    inertiaVisit($method, $url, $page)->assertRedirect(route('reauthenticate.show'));

    $landing = $confirm();

    $this->get($landing)->assertOk();
    expect($landing)->toBe($page);
    expect(inertiaVisit($method, $url, $page)->headers->get('Location'))->not->toBe(route('reauthenticate.show'));
})->with('guarded actions')->with('factors');

it('lands on the dashboard when the browser sends no Referer', function (array $action, string $factor) {
    [$method, $url] = $action;
    [$admin, $confirm] = stepUpAdmin($factor);

    $this->actingAs($admin);
    inertiaVisit($method, $url, null)->assertRedirect(route('reauthenticate.show'));

    $landing = $confirm();

    $this->get($landing)->assertOk();
    expect($landing)->toBe(route('dashboard'));
})->with('guarded actions')->with('factors');

it('lands on the dashboard rather than follow a Referer that is not a page of this app', function (string $referer, string $factor) {
    [$admin, $confirm] = stepUpAdmin($factor);

    $this->actingAs($admin);
    inertiaVisit('POST', route('admin.users.sign-out', User::factory()->create()), $referer)
        ->assertRedirect(route('reauthenticate.show'));

    $landing = $confirm();

    $this->get($landing)->assertOk();
    expect($landing)->toBe(route('dashboard'));
})->with('foreign referers')->with('factors');

it('sends a guarded page back to itself', function (string $factor) {
    Route::middleware(['web', 'auth', 'reauth'])->get('guarded-page', fn () => 'guarded page');
    [$admin, $confirm] = stepUpAdmin($factor);

    $this->actingAs($admin)
        ->get('/guarded-page?tab=keys', ['Referer' => route('dashboard')])
        ->assertRedirect(route('reauthenticate.show'));

    $landing = $confirm();

    $this->get($landing)->assertOk()->assertSee('guarded page');
    expect($landing)->toBe(url('/guarded-page?tab=keys'));
})->with('factors');

it('guards secret rotation, role changes, deletion and code regeneration', function () {
    $admin = User::factory()->admin()->create();
    $application = Application::create(['name' => 'Zero', 'slug' => 'zero', 'active' => true]);
    $other = User::factory()->create();

    $this->actingAs($admin);

    $this->post(route('admin.applications.rotate-secret', $application))
        ->assertRedirect(route('reauthenticate.show'));
    $this->put(route('admin.users.role.update', $other))
        ->assertRedirect(route('reauthenticate.show'));
    $this->post(route('recovery-codes.regenerate'))
        ->assertRedirect(route('reauthenticate.show'));
    $this->delete(route('profile.destroy'))
        ->assertRedirect(route('reauthenticate.show'));
});

it('offers the passkey path only when one is enrolled', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('reauthenticate.show'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('auth/Reauthenticate')
            ->where('hasPasskeys', false)
        );
});
