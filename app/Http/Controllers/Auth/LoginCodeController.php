<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\SendLoginCode;
use App\Actions\Auth\VerifyLoginCode;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LoginCodeController extends Controller
{
    public function send(Request $request, SendLoginCode $sendLoginCode): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        $email = $request->string('email')->toString();
        $user = User::where('email', $email)->first();

        if ($user) {
            $sendLoginCode->handle($user);
        }

        return redirect()->route('login')
            ->with('login_email', $email)
            ->with('code_sent', true)
            ->with('status', 'If that email belongs to an account, a login code is on its way.');
    }

    public function verify(Request $request, VerifyLoginCode $verifyLoginCode): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'string'],
        ]);

        $email = $request->string('email')->toString();
        $user = User::where('email', $email)->first();

        if ($user && $verifyLoginCode->handle($user, $request->string('code')->toString())) {
            Auth::login($user, remember: true);
            $request->session()->regenerate();

            return redirect()->intended(route('dashboard', absolute: false));
        }

        return redirect()->route('login')
            ->with('login_email', $email)
            ->with('code_sent', true)
            ->withErrors(['code' => 'That code is invalid or has expired.']);
    }
}
