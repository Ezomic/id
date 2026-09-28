<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\RecordFailedSignIn;
use App\Actions\Auth\SendLoginCode;
use App\Actions\Auth\VerifyLoginCode;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class LoginCodeController extends Controller
{
    public function send(Request $request, SendLoginCode $sendLoginCode, RecordFailedSignIn $recordFailure): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        $email = $request->string('email')->toString();
        $user = User::where('email', $email)->first();

        if ($user) {
            $sendLoginCode->handle($user);
        } else {
            $recordFailure->handle(null, 'email_code');
        }

        return redirect()->route('login')
            ->with('login_email', $email)
            ->with('code_sent', true)
            ->with('status', 'If that email belongs to an account, a login code is on its way.');
    }

    public function verify(Request $request, VerifyLoginCode $verifyLoginCode, RecordFailedSignIn $recordFailure): Response
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

            // A full page visit, not a redirect the form's XHR would follow: when
            // the sign-in began at an app, the intended URL is /oauth/authorize,
            // which redirects to that app's origin and fails the CORS preflight.
            return Inertia::location(redirect()->intended(route('dashboard', absolute: false)));
        }

        $recordFailure->handle($user, 'email_code');

        return redirect()->route('login')
            ->with('login_email', $email)
            ->with('code_sent', true)
            ->withErrors(['code' => 'That code is invalid or has expired.']);
    }
}
