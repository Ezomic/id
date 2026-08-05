<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SuspiciousSignInAttempts extends Notification
{
    public function __construct(
        private readonly int $attempts,
        private readonly int $windowMinutes,
        private readonly ?string $ip,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Failed sign-in attempts on your Thijssensoftware ID')
            ->greeting('Someone is failing to sign in as you')
            ->line("There have been {$this->attempts} failed sign-in attempts on your account in the last {$this->windowMinutes} minutes.")
            ->line('Most recent attempt from: '.($this->ip ?? 'an unknown IP address'))
            ->line('If this was you, no action is needed. Request a fresh login code and try again.')
            ->line('If it was not, adding a passkey makes your account much harder to attack.')
            ->action('Review your sign-in history', route('sign-in-history.edit'));
    }
}
