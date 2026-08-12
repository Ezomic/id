<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia;

it('prompts an account with no passkey', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('auth.needsPasskey', true));
});

it('stops prompting once a passkey exists', function () {
    $user = User::factory()->create();

    $user->passkeys()->create([
        'name' => 'Laptop',
        'credential_id' => 'cred-1',
        'credential' => ['id' => 'cred-1'],
    ]);

    expect($user->fresh()->needsPasskeyPrompt())->toBeFalse();
});

it('snoozes the prompt rather than silencing it', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->delete(route('security.passkey-prompt.dismiss'))->assertRedirect();

    expect($user->fresh()->needsPasskeyPrompt())->toBeFalse();

    // The account still has no passkey, so the risk has not gone away and the
    // nudge comes back rather than being dismissed for good.
    $this->travel(User::PASSKEY_PROMPT_SNOOZE_DAYS + 1)->days();

    expect($user->fresh()->needsPasskeyPrompt())->toBeTrue();
});

it('does not nag within the snooze window', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->delete(route('security.passkey-prompt.dismiss'));

    $this->travel(User::PASSKEY_PROMPT_SNOOZE_DAYS - 1)->days();

    expect($user->fresh()->needsPasskeyPrompt())->toBeFalse();
});

it('never prompts a signed-out visitor', function () {
    $this->get(route('home'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('auth.needsPasskey', false));
});
