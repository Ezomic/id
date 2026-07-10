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
        $data = $request->validate(['email' => ['required', 'email']]);

        $user = User::where('email', $data['email'])->first();

        if ($user) {
            $sendLoginCode->handle($user);
        }

        return redirect()->route('login')
            ->with('login_email', $data['email'])
            ->with('code_sent', true)
            ->with('status', 'If that email belongs to an account, a login code is on its way.');
    }

    public function verify(Request $request, VerifyLoginCode $verifyLoginCode): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'string'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if ($user && $verifyLoginCode->handle($user, $data['code'])) {
            Auth::login($user, remember: true);
            $request->session()->regenerate();

            return redirect()->intended(route('dashboard', absolute: false));
        }

        return redirect()->route('login')
            ->with('login_email', $data['email'])
            ->with('code_sent', true)
            ->withErrors(['code' => 'That code is invalid or has expired.']);
    }
}
