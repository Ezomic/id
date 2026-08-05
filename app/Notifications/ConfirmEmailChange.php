<?php

namespace App\Notifications;

use App\Actions\Settings\RequestEmailChange;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ConfirmEmailChange extends Notification
{
    public function __construct(
        private readonly string $token,
        private readonly string $email,
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
            ->subject('Confirm your new Thijssensoftware ID email address')
            ->greeting('Confirm your new address')
            ->line("Someone asked to move a Thijssensoftware ID account to {$this->email}.")
            ->line('Your existing address keeps working until you confirm, so nothing changes if you ignore this.')
            ->action('Confirm this address', route('profile.email.confirm', ['token' => $this->token]))
            ->line('This link expires in '.RequestEmailChange::EXPIRY_MINUTES.' minutes.');
    }
}
