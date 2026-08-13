<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Models\User;
use App\Notifications\UserInvited;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class InviteUser
{
    public const EXPIRY_DAYS = 7;

    /**
     * Creating a user used to write a row and stop. Nothing was sent, so the
     * person had no idea an account existed and no way to discover the estate
     * did either; someone had to tell them out of band to visit a URL and
     * request a code for an address they did not know was registered.
     *
     * Returns the plaintext token, which exists nowhere else.
     */
    public function handle(User $user, User $invitedBy): string
    {
        $token = Str::random(64);

        $user->forceFill([
            'invitation_token' => Hash::make($token),
            'invitation_expires_at' => CarbonImmutable::now()->addDays(self::EXPIRY_DAYS),
            'invitation_accepted_at' => null,
        ])->save();

        $user->notify(new UserInvited($token, $invitedBy->name));

        return $token;
    }
}
