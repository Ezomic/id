<?php

declare(strict_types=1);

namespace App\Actions\Settings;

use App\Models\User;
use App\Notifications\ConfirmEmailChange;
use App\Notifications\EmailChangeRequested;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

final class RequestEmailChange
{
    public const EXPIRY_MINUTES = 60;

    /**
     * The address is held pending rather than written straight to the user row.
     * Login codes go to whatever is in `email`, so applying an unconfirmed
     * address would lock the account out of every consumer app on a typo, and
     * would let someone point their ID at a colleague's address to match that
     * colleague's local account the next time they sign in to a new app.
     */
    public function handle(User $user, string $email): void
    {
        $token = Str::random(64);

        $user->forceFill([
            'pending_email' => $email,
            'pending_email_token' => Hash::make($token),
            'pending_email_expires_at' => CarbonImmutable::now()->addMinutes(self::EXPIRY_MINUTES),
        ])->save();

        Notification::route('mail', $email)->notify(new ConfirmEmailChange($token, $email));

        // The address losing control of the account is the one that most needs
        // to hear about it.
        $user->notify(new EmailChangeRequested($email));
    }
}
