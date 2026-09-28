<?php

use App\Actions\Auth\SendLoginCode;
use App\Actions\Auth\VerifyLoginCode;
use App\Mail\LoginCodeMail;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Passport\ClientRepository;

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

it('burns the code after too many wrong guesses', function () {
    $user = User::factory()->create([
        'login_code_hash' => Hash::make('123456'),
        'login_code_expires_at' => now()->addMinutes(10),
    ]);

    $verify = app(VerifyLoginCode::class);

    foreach (range(1, VerifyLoginCode::MAX_ATTEMPTS) as $attempt) {
        expect($verify->handle($user, '000000'))->toBeFalse();
    }

    expect($user->fresh()->login_code_hash)->toBeNull();
    expect($verify->handle($user->fresh(), '123456'))->toBeFalse();
});

it('counts wrong guesses without burning the code below the ceiling', function () {
    $user = User::factory()->create([
        'login_code_hash' => Hash::make('123456'),
        'login_code_expires_at' => now()->addMinutes(10),
    ]);

    $verify = app(VerifyLoginCode::class);

    foreach (range(1, VerifyLoginCode::MAX_ATTEMPTS - 1) as $attempt) {
        $verify->handle($user, '000000');
    }

    expect($user->fresh()->login_code_attempts)->toBe(VerifyLoginCode::MAX_ATTEMPTS - 1);
    expect($verify->handle($user->fresh(), '123456'))->toBeTrue();
});

it('resets the attempt counter when a new code is issued', function () {
    Mail::fake();

    $user = User::factory()->create([
        'login_code_hash' => Hash::make('123456'),
        'login_code_expires_at' => now()->addMinutes(10),
        'login_code_attempts' => 3,
    ]);

    app(SendLoginCode::class)->handle($user);

    expect($user->fresh()->login_code_attempts)->toBe(0);
});

it('clears the attempt counter on a successful sign-in', function () {
    $user = User::factory()->create([
        'login_code_hash' => Hash::make('123456'),
        'login_code_expires_at' => now()->addMinutes(10),
        'login_code_attempts' => 2,
    ]);

    $this->post(route('login.code.verify'), ['email' => $user->email, 'code' => '123456'])
        ->assertRedirect(route('dashboard'));

    expect($user->fresh()->login_code_attempts)->toBe(0);
});

it('takes a sign-in that began at an app straight on to that app', function () {
    $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient(
        name: 'Tracker',
        redirectUris: ['https://tracker.test/auth/sso/callback'],
        confidential: true,
    );

    $user = User::factory()->create([
        'login_code_hash' => Hash::make('123456'),
        'login_code_expires_at' => now()->addMinutes(10),
    ]);

    $this->get('/oauth/authorize?'.http_build_query([
        'client_id' => $client->getKey(),
        'redirect_uri' => 'https://tracker.test/auth/sso/callback',
        'response_type' => 'code',
        'scope' => '',
        'state' => 'state',
    ]))->assertRedirect(route('login'));

    $authorize = session('url.intended');
    expect($authorize)->toStartWith(url('/oauth/authorize?'));

    // The code form is an Inertia XHR. A plain redirect would be followed by
    // that XHR to the app's callback on another origin, where it fails CORS.
    $this->post(
        route('login.code.verify'),
        ['email' => $user->email, 'code' => '123456'],
        ['X-Inertia' => 'true'],
    )
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', $authorize);

    $this->get($authorize)->assertRedirectContains('https://tracker.test/auth/sso/callback?code=');
});

it('still redirects a plain form post to where the sign-in began', function () {
    $user = User::factory()->create([
        'login_code_hash' => Hash::make('123456'),
        'login_code_expires_at' => now()->addMinutes(10),
    ]);

    $authorize = url('/oauth/authorize?client_id=tracker&response_type=code');

    $this->withSession(['url.intended' => $authorize])
        ->post(route('login.code.verify'), ['email' => $user->email, 'code' => '123456'])
        ->assertRedirect($authorize);
});

it('sends an Inertia sign-in with nowhere to return to on to the dashboard', function () {
    $user = User::factory()->create([
        'login_code_hash' => Hash::make('123456'),
        'login_code_expires_at' => now()->addMinutes(10),
    ]);

    $this->post(
        route('login.code.verify'),
        ['email' => $user->email, 'code' => '123456'],
        ['X-Inertia' => 'true'],
    )
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', route('dashboard'));

    $this->assertAuthenticatedAs($user);
});
