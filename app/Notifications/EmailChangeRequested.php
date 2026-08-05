<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EmailChangeRequested extends Notification
{
    public function __construct(private readonly string $newEmail) {}

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
            ->subject('A change of email address was requested on your Thijssensoftware ID')
            ->greeting('Someone asked to change your email address')
            ->line("A request was made to move your account to {$this->newEmail}.")
            ->line('This address stays in control of the account until that new one is confirmed.')
            ->line('If this was not you, cancel the request from your profile settings and add a passkey.')
            ->action('Review your profile', route('profile.edit'));
    }
}
