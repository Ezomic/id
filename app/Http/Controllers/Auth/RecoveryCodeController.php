<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\RecordFailedSignIn;
use App\Actions\Auth\RedeemRecoveryCode;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;

class RecoveryCodeController extends Controller
{
    public function redeem(
        Request $request,
        RedeemRecoveryCode $redeem,
        RecordFailedSignIn $recordFailure,
    ): RedirectResponse {
        $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'string'],
        ]);

        $email = $request->string('email')->toString();
        $user = User::where('email', $email)->first();

        if ($user !== null && $this->exhausted($user)) {
            return $this->failed($email, 'Too many recovery attempts. Try again later.');
        }

        if ($user !== null && $redeem->handle($user, $request->string('code')->toString())) {
            RateLimiter::clear($this->throttleKey($user));

            Auth::login($user, remember: true);
            $request->session()->regenerate();

            return redirect()->intended(route('dashboard', absolute: false));
        }

        if ($user !== null) {
            RateLimiter::hit($this->throttleKey($user), 3600);
        }

        $recordFailure->handle($user, 'recovery_code');

        return $this->failed($email, 'That recovery code is not valid.');
    }

    /**
     * Per user rather than per IP, so recovery cannot be used as a quieter way
     * to hammer one account from many addresses.
     */
    private function exhausted(User $user): bool
    {
        return RateLimiter::tooManyAttempts(
            $this->throttleKey($user),
            RedeemRecoveryCode::MAX_ATTEMPTS_PER_HOUR,
        );
    }

    private function throttleKey(User $user): string
    {
        return 'recovery-code:'.$user->id;
    }

    private function failed(string $email, string $message): RedirectResponse
    {
        return redirect()->route('login')
            ->with('login_email', $email)
            ->with('recovery_mode', true)
            ->withErrors(['code' => $message]);
    }
}
