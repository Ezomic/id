<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewDeviceSignIn extends Notification
{
    public function __construct(
        private readonly string $method,
        private readonly ?string $ip,
        private readonly string $device,
        private readonly bool $newDevice,
        private readonly bool $newNetwork,
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
            ->subject($this->subject())
            ->greeting($this->subject())
            ->line($this->summary())
            ->line('Method: '.$this->methodLabel())
            ->line('Device: '.$this->device)
            ->line('IP address: '.($this->ip ?? 'unknown'))
            ->line('If this was you, no action is needed.')
            ->line('If it was not, revoke the session and add a passkey.')
            ->action('Review your sessions', route('sessions.edit'));
    }

    private function subject(): string
    {
        return match (true) {
            $this->newDevice && $this->newNetwork => 'New sign-in to your Thijssensoftware ID',
            $this->newDevice => 'New device signed in to your Thijssensoftware ID',
            default => 'Sign-in from a new location to your Thijssensoftware ID',
        };
    }

    private function summary(): string
    {
        return match (true) {
            $this->newDevice && $this->newNetwork => 'Your account was signed in to from a device and a network we have not seen before.',
            $this->newDevice => 'Your account was signed in to from a device we have not seen before.',
            default => 'Your account was signed in to from a familiar device, but on a network we have not seen before.',
        };
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
