<?php

use App\Mail\LoginCodeMail;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

it('emails a login code to a known account', function () {
    Mail::fake();

    $user = User::factory()->create();

    $this->post(route('login.code.send'), ['email' => $user->email])
        ->assertRedirect(route('login'))
        ->assertSessionHas('code_sent', true);

    expect($user->fresh()->login_code_hash)->not->toBeNull();

    Mail::assertSent(LoginCodeMail::class);
});

it('does not reveal whether an email exists', function () {
    Mail::fake();

    $this->post(route('login.code.send'), ['email' => 'nobody@example.com'])
        ->assertRedirect(route('login'))
        ->assertSessionHas('code_sent', true);

    Mail::assertNothingSent();
});

it('logs in with a valid code', function () {
    $user = User::factory()->create([
        'login_code_hash' => Hash::make('123456'),
        'login_code_expires_at' => now()->addMinutes(10),
    ]);

    $this->post(route('login.code.verify'), ['email' => $user->email, 'code' => '123456'])
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->login_code_hash)->toBeNull();
});

it('rejects an invalid code', function () {
    $user = User::factory()->create([
        'login_code_hash' => Hash::make('123456'),
        'login_code_expires_at' => now()->addMinutes(10),
    ]);

    $this->post(route('login.code.verify'), ['email' => $user->email, 'code' => '000000'])
        ->assertSessionHasErrors('code');

    $this->assertGuest();
});

it('rejects an expired code', function () {
    $user = User::factory()->create([
        'login_code_hash' => Hash::make('123456'),
        'login_code_expires_at' => now()->subMinute(),
    ]);

    $this->post(route('login.code.verify'), ['email' => $user->email, 'code' => '123456'])
        ->assertSessionHasErrors('code');

    $this->assertGuest();
});
