<?php

namespace App\Notifications;

use App\Actions\Auth\RedeemRecoveryCode;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RecoveryCodeUsed extends Notification
{
    public function __construct(private readonly int $remaining) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject('A recovery code was used on your Thijssensoftware ID')
            ->greeting('A recovery code was just used')
            ->line('Someone signed in to your account with one of your recovery codes. That code is now spent and cannot be used again.')
            ->line("You have {$this->remaining} unused codes left.");

        if ($this->remaining <= RedeemRecoveryCode::LOW_WATERMARK) {
            $message->line('That is running low. Generate a fresh set from your security settings.');
        }

        return $message
            ->line('If this was not you, generate a new set immediately, which invalidates every existing code.')
            ->action('Open security settings', route('security.edit'));
    }
}
