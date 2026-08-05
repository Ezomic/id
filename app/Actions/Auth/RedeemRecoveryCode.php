<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\RecoveryCode;
use App\Models\User;
use App\Notifications\RecoveryCodeUsed;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Hash;

final class RedeemRecoveryCode
{
    /**
     * Codes are 12 characters, so guessing is not the threat the login code
     * ceiling defends against. The cap is here so recovery cannot be used as a
     * quieter way to hammer an account than the email code path.
     */
    public const MAX_ATTEMPTS_PER_HOUR = 10;

    /**
     * Warn while there is still time to act rather than at zero.
     */
    public const LOW_WATERMARK = 3;

    public function handle(User $user, string $code): bool
    {
        $normalised = $this->normalise($code);

        $match = $user->recoveryCodes()
            ->whereNull('used_at')
            ->get()
            ->first(fn (RecoveryCode $candidate): bool => Hash::check($normalised, $candidate->code_hash));

        if ($match === null) {
            return false;
        }

        $match->forceFill(['used_at' => CarbonImmutable::now()])->save();

        $user->notify(new RecoveryCodeUsed($user->recoveryCodes()->whereNull('used_at')->count()));

        return true;
    }

    /**
     * Codes are shown grouped and uppercase; accept them typed back however the
     * user managed it.
     */
    private function normalise(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
    }
}
