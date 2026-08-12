<?php

use App\Actions\Auth\GenerateRecoveryCodes;
use App\Http\Middleware\RequireRecentAuthentication;
use App\Models\AccessAudit;
use App\Models\Application;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Inertia\Testing\AssertableInertia;

function confirmedAdmin(): User
{
    $admin = User::factory()->admin()->create();
    test()->actingAs($admin);
    session(['auth.password_confirmed_at' => Date::now()->unix()]);

    return $admin;
}

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

it('returns to the action that triggered the prompt', function () {
    $admin = User::factory()->admin()->create();
    $codes = app(GenerateRecoveryCodes::class)->handle($admin);
    $user = User::factory()->create();

    $this->actingAs($admin)->post(route('admin.users.sign-out', $user));

    $this->post(route('reauthenticate.confirm'), ['code' => $codes[0]])
        ->assertRedirect(route('admin.users.sign-out', $user));
});

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
