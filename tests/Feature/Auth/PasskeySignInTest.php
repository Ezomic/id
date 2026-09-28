<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;

/**
 * @param  array<string, mixed>  $extra
 */
function signInWithPasskey(User $user, OpenSSLAsymmetricKey $key, array $extra = []): TestResponse
{
    return passkeyCeremony($user, $key, route('passkey.login-options'), route('passkey.login'), $extra);
}

it('remembers the device after a passkey sign-in, whatever the client sends', function (array $extra) {
    [$user, $key] = passkeyHolder();

    signInWithPasskey($user, $key, $extra)
        ->assertOk()
        ->assertCookie(Auth::guard('web')->getRecallerName());

    $this->assertAuthenticatedAs($user);
})->with([
    'remember left out' => [[]],
    'remember turned off' => [['remember' => false]],
]);

it('hands the passkey client the page the sign-in began at', function () {
    [$user, $key] = passkeyHolder();

    $authorize = url('/oauth/authorize?client_id=tracker&response_type=code');

    $this->withSession(['url.intended' => $authorize]);

    signInWithPasskey($user, $key)
        ->assertOk()
        ->assertExactJson(['redirect' => $authorize]);
});
