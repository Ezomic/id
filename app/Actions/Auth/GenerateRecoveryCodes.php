<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class GenerateRecoveryCodes
{
    public const COUNT = 10;

    /**
     * Regenerating replaces the whole set: a user who thinks their codes may
     * have been seen needs every old one dead, not just the unused ones.
     *
     * The plaintext is returned for a single display and never stored, so this
     * return value is the only chance the user gets to save them.
     *
     * @return list<string>
     */
    public function handle(User $user): array
    {
        $user->recoveryCodes()->delete();

        $codes = [];

        foreach (range(1, self::COUNT) as $index) {
            $raw = Str::upper(Str::random(12));

            // Hashed without the separators so a code still matches however the
            // user types it back; see RedeemRecoveryCode::normalise().
            $user->recoveryCodes()->create(['code_hash' => Hash::make($raw)]);

            $codes[] = implode('-', str_split($raw, 4));
        }

        return $codes;
    }
}
