<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

class VerifyLoginCode
{
    /**
     * The rate limiter is keyed on email plus IP, so it bounds one attacker but
     * not a distributed one. Burning the code after a fixed number of wrong
     * guesses bounds the whole 6-digit space regardless of where guesses come from.
     */
    public const MAX_ATTEMPTS = 5;

    public function handle(User $user, string $code): bool
    {
        if (! $user->login_code_hash || ! $user->login_code_expires_at) {
            return false;
        }

        if ($user->login_code_expires_at->isPast()) {
            return false;
        }

        if (! Hash::check($code, $user->login_code_hash)) {
            $this->recordFailure($user);

            return false;
        }

        $this->clearCode($user);

        return true;
    }

    private function recordFailure(User $user): void
    {
        $attempts = $user->login_code_attempts + 1;

        if ($attempts >= self::MAX_ATTEMPTS) {
            $this->clearCode($user);

            return;
        }

        $user->forceFill(['login_code_attempts' => $attempts])->save();
    }

    private function clearCode(User $user): void
    {
        $user->forceFill([
            'login_code_hash' => null,
            'login_code_expires_at' => null,
            'login_code_attempts' => 0,
        ])->save();
    }
}
