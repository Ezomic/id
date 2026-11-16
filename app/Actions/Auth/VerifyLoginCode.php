<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

class VerifyLoginCode
{
    /**
     * Burning the code after a fixed number of wrong guesses bounds the whole
     * 6-digit space regardless of where guesses come from. The per-email rate
     * limit bounds how many codes can be burned.
     */
    public const MAX_ATTEMPTS = 5;

    public function handle(User $user, string $code): bool
    {
        if (! $this->reserveAttempt($user)) {
            return false;
        }

        $hash = User::whereKey($user->getKey())->value('login_code_hash');

        if (! is_string($hash) || ! Hash::check($code, $hash)) {
            $this->burnIfExhausted($user);

            return false;
        }

        $this->clearCode($user);

        return true;
    }

    /**
     * Counted in one conditional UPDATE before the slow hash check, so parallel
     * guesses cannot all read the same count and each get a free attempt.
     */
    private function reserveAttempt(User $user): bool
    {
        return User::whereKey($user->getKey())
            ->whereNotNull('login_code_hash')
            ->where('login_code_expires_at', '>', now())
            ->where('login_code_attempts', '<', self::MAX_ATTEMPTS)
            ->increment('login_code_attempts') === 1;
    }

    private function burnIfExhausted(User $user): void
    {
        User::whereKey($user->getKey())
            ->where('login_code_attempts', '>=', self::MAX_ATTEMPTS)
            ->update([
                'login_code_hash' => null,
                'login_code_expires_at' => null,
                'login_code_attempts' => 0,
            ]);
    }

    /**
     * A query rather than a save: the loaded model still holds the count from
     * before reserveAttempt(), so a save would see no change and skip it.
     */
    private function clearCode(User $user): void
    {
        User::whereKey($user->getKey())->update([
            'login_code_hash' => null,
            'login_code_expires_at' => null,
            'login_code_attempts' => 0,
        ]);
    }
}
