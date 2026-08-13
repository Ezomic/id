<?php

namespace App\Notifications;

use App\Actions\Admin\InviteUser;
use App\Models\Application;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class UserInvited extends Notification
{
    public function __construct(
        private readonly string $token,
        private readonly string $invitedBy,
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
        $message = (new MailMessage)
            ->subject('You have been added to Thijssensoftware ID')
            ->greeting('You have an account')
            ->line("{$this->invitedBy} created an account for you on Thijssensoftware ID, which is the single sign-in for the Thijssensoftware apps.");

        $apps = $notifiable instanceof User ? $this->applicationNames($notifiable) : '';

        if ($apps !== '') {
            $message->line("You can reach: {$apps}.");
        }

        return $message
            ->line('There is no password to set. Signing in is a one-time code by email, or a passkey once you add one.')
            ->action('Sign in', route('invitations.accept', ['token' => $this->token]))
            ->line('This link expires in '.InviteUser::EXPIRY_DAYS.' days. After that, sign in from the front page instead.');
    }

    private function applicationNames(User $user): string
    {
        return Application::query()
            ->whereIn('id', $user->accessibleApplicationIds())
            ->where('active', true)
            ->orderBy('name')
            ->pluck('name')
            ->implode(', ');
    }
}
