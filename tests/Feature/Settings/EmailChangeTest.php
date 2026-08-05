<?php

use App\Actions\Settings\RequestEmailChange;
use App\Models\User;
use App\Notifications\ConfirmEmailChange;
use App\Notifications\EmailChangeRequested;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;

function requestChange(User $user, string $email = 'new@example.com'): string
{
    $token = 'confirmation-token';

    $user->forceFill([
        'pending_email' => $email,
        'pending_email_token' => Hash::make($token),
        'pending_email_expires_at' => now()->addMinutes(RequestEmailChange::EXPIRY_MINUTES),
    ])->save();

    return $token;
}

it('does not apply an unconfirmed email change', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'old@example.com']);

    $this->actingAs($user)
        ->patch(route('profile.update'), ['name' => $user->name, 'email' => 'new@example.com'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    $user->refresh();

    expect($user->email)->toBe('old@example.com')
        ->and($user->pending_email)->toBe('new@example.com')
        ->and($user->email_verified_at)->not->toBeNull();
});

it('emails the new address and warns the current one', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'old@example.com']);

    $this->actingAs($user)
        ->patch(route('profile.update'), ['name' => $user->name, 'email' => 'new@example.com']);

    Notification::assertSentTo($user, EmailChangeRequested::class);
    Notification::assertSentTo(
        new AnonymousNotifiable,
        ConfirmEmailChange::class,
        fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'new@example.com',
    );
});

it('applies the change once confirmed', function () {
    $user = User::factory()->create(['email' => 'old@example.com', 'email_verified_at' => null]);
    $token = requestChange($user);

    $this->actingAs($user)
        ->get(route('profile.email.confirm', ['token' => $token]))
        ->assertRedirect(route('profile.edit'));

    $user->refresh();

    expect($user->email)->toBe('new@example.com')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->pending_email)->toBeNull()
        ->and($user->pending_email_token)->toBeNull();
});

it('rejects an expired confirmation', function () {
    $user = User::factory()->create(['email' => 'old@example.com']);
    $token = requestChange($user);

    $user->forceFill(['pending_email_expires_at' => now()->subMinute()])->save();

    $this->actingAs($user)
        ->get(route('profile.email.confirm', ['token' => $token]))
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh()->email)->toBe('old@example.com');
});

it('rejects a wrong token', function () {
    $user = User::factory()->create(['email' => 'old@example.com']);
    requestChange($user);

    $this->actingAs($user)
        ->get(route('profile.email.confirm', ['token' => 'not-the-token']))
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh()->email)->toBe('old@example.com');
});

it('refuses to confirm an address someone else claimed in the meantime', function () {
    $user = User::factory()->create(['email' => 'old@example.com']);
    $token = requestChange($user);

    User::factory()->create(['email' => 'new@example.com']);

    $this->actingAs($user)
        ->get(route('profile.email.confirm', ['token' => $token]))
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh()->email)->toBe('old@example.com');
});

it('cannot confirm another account\'s pending change', function () {
    $user = User::factory()->create(['email' => 'old@example.com']);
    $token = requestChange($user);

    $other = User::factory()->create();

    $this->actingAs($other)
        ->get(route('profile.email.confirm', ['token' => $token]))
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh()->email)->toBe('old@example.com');
});

it('lets the user cancel a pending change', function () {
    $user = User::factory()->create(['email' => 'old@example.com']);
    requestChange($user);

    $this->actingAs($user)
        ->delete(route('profile.email.cancel'))
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh()->pending_email)->toBeNull();
});

it('shows the pending address on the profile page', function () {
    $user = User::factory()->create(['email' => 'old@example.com']);
    requestChange($user);

    $this->actingAs($user)
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('settings/Profile')
            ->where('pendingEmail', 'new@example.com')
        );
});

it('rejects an address already in use', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'old@example.com']);
    User::factory()->create(['email' => 'taken@example.com']);

    $this->actingAs($user)
        ->patch(route('profile.update'), ['name' => $user->name, 'email' => 'taken@example.com'])
        ->assertSessionHasErrors('email');

    expect($user->refresh()->pending_email)->toBeNull();
});
