<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewDeviceSignIn extends Notification
{
    public function __construct(
        private readonly string $method,
        private readonly ?string $ip,
        private readonly ?string $userAgent,
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
            ->subject('New sign-in to your Thijssensoftware ID')
            ->greeting('New sign-in detected')
            ->line('Your account was just signed in to from a device we have not seen before.')
            ->line('Method: '.$this->methodLabel())
            ->line('IP address: '.($this->ip ?? 'unknown'))
            ->line('Device: '.($this->userAgent ?: 'unknown'))
            ->line('If this was you, no action is needed.')
            ->line('If it was not, revoke the session and add a passkey.')
            ->action('Review your sessions', route('sessions.edit'));
    }

    private function methodLabel(): string
    {
        return match ($this->method) {
            'passkey' => 'Passkey',
            'email_code' => 'Email code',
            default => 'Other',
        };
    }
}
