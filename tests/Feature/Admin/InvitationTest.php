<?php

use App\Actions\Admin\InviteUser;
use App\Models\Application;
use App\Models\User;
use App\Notifications\UserInvited;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

beforeEach(fn () => confirmSession());

it('invites on create when asked', function () {
    Notification::fake();
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post(route('admin.users.store'), [
        'name' => 'New Person',
        'email' => 'new@example.com',
        'invite' => true,
    ])->assertRedirect();

    $invitee = User::where('email', 'new@example.com')->firstOrFail();

    expect($invitee->invitation_token)->not->toBeNull();
    Notification::assertSentTo($invitee, UserInvited::class);
});

it('does not send anything when not asked', function () {
    Notification::fake();
    $admin = User::factory()->admin()->create();

    // Scripted setup should not send mail.
    $this->actingAs($admin)->post(route('admin.users.store'), [
        'name' => 'Quiet Person',
        'email' => 'quiet@example.com',
    ])->assertRedirect();

    expect(User::where('email', 'quiet@example.com')->value('invitation_token'))->toBeNull();
    Notification::assertNothingSent();
});

it('signs the invitee in from the link', function () {
    $admin = User::factory()->admin()->create();
    $invitee = User::factory()->create(['email_verified_at' => null]);

    $token = app(InviteUser::class)->handle($invitee, $admin);

    $this->get(route('invitations.accept', ['token' => $token]))
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($invitee);

    $invitee->refresh();

    expect($invitee->invitation_token)->toBeNull()
        ->and($invitee->invitation_accepted_at)->not->toBeNull()
        // Arriving via a secret sent to the address proves the address.
        ->and($invitee->email_verified_at)->not->toBeNull();
});

it('refuses a used invitation', function () {
    $admin = User::factory()->admin()->create();
    $invitee = User::factory()->create();

    $token = app(InviteUser::class)->handle($invitee, $admin);

    $this->get(route('invitations.accept', ['token' => $token]));
    $this->post(route('logout'));

    $this->get(route('invitations.accept', ['token' => $token]))
        ->assertRedirect(route('login'));

    $this->assertGuest();
});

it('refuses an expired invitation', function () {
    $admin = User::factory()->admin()->create();
    $invitee = User::factory()->create();

    $token = app(InviteUser::class)->handle($invitee, $admin);

    $this->travel(InviteUser::EXPIRY_DAYS + 1)->days();

    $this->get(route('invitations.accept', ['token' => $token]))
        ->assertRedirect(route('login'));

    $this->assertGuest();
});

it('refuses a token that was never issued', function () {
    User::factory()->create();

    $this->get(route('invitations.accept', ['token' => 'made-up']))
        ->assertRedirect(route('login'));

    $this->assertGuest();
});

it('resends an invitation with a fresh token', function () {
    Notification::fake();
    $admin = User::factory()->admin()->create();
    $invitee = User::factory()->create();

    $first = app(InviteUser::class)->handle($invitee, $admin);

    $this->actingAs($admin)->post(route('admin.users.invite', $invitee))->assertRedirect();

    // The old link must stop working, otherwise resending widens the window
    // rather than replacing it.
    expect(Hash::check($first, (string) $invitee->fresh()->invitation_token))->toBeFalse();
});

it('names the apps the invitee can reach', function () {
    Notification::fake();
    $admin = User::factory()->admin()->create();
    $invitee = User::factory()->create();
    $application = Application::create(['name' => 'Billr', 'slug' => 'billr', 'active' => true]);
    $invitee->applications()->attach($application);

    app(InviteUser::class)->handle($invitee, $admin);

    Notification::assertSentTo($invitee, UserInvited::class, function ($notification) use ($invitee) {
        return str_contains($notification->toMail($invitee)->render(), 'Billr');
    });
});

it('keeps inviting away from non-admins', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    $this->actingAs($user)->post(route('admin.users.invite', $other))->assertForbidden();
});
