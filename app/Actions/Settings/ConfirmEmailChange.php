<?php

declare(strict_types=1);

namespace App\Actions\Settings;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Hash;

final class ConfirmEmailChange
{
    public function handle(User $user, string $token): bool
    {
        if ($user->pending_email === null || $user->pending_email_token === null) {
            return false;
        }

        if ($user->pending_email_expires_at === null || $user->pending_email_expires_at->isPast()) {
            return false;
        }

        if (! Hash::check($token, $user->pending_email_token)) {
            return false;
        }

        // Someone else may have claimed the address in the meantime, and the
        // users table has a unique index that would fail the write.
        if (User::query()->where('email', $user->pending_email)->whereKeyNot($user->id)->exists()) {
            return false;
        }

        $user->forceFill([
            'email' => $user->pending_email,
            'email_verified_at' => CarbonImmutable::now(),
            'pending_email' => null,
            'pending_email_token' => null,
            'pending_email_expires_at' => null,
        ])->save();

        return true;
    }
}
