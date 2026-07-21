<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AccessRequestDecided extends Notification
{
    public function __construct(
        private readonly string $application,
        private readonly bool $approved,
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
        if ($this->approved) {
            return (new MailMessage)
                ->subject("Access granted: {$this->application}")
                ->line("Your request for access to {$this->application} was approved.")
                ->action('Open the portal', route('dashboard'));
        }

        return (new MailMessage)
            ->subject("Access request declined: {$this->application}")
            ->line("Your request for access to {$this->application} was not approved.")
            ->line('If you think this is a mistake, reach out to an administrator.');
    }
}
