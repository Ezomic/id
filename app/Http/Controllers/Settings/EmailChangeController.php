<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\Auth\NotifyClientsOfEvent;
use App\Actions\Settings\ConfirmEmailChange;
use App\Concerns\InteractsWithCurrentUser;
use App\Http\Controllers\Controller;
use App\Models\LogoutNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class EmailChangeController extends Controller
{
    use InteractsWithCurrentUser;

    public function confirm(Request $request, string $token, ConfirmEmailChange $confirm, NotifyClientsOfEvent $notifyClients): RedirectResponse
    {
        $user = $this->currentUser($request);

        if (! $confirm->handle($user, $token)) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('That confirmation link is invalid or has expired.')]);

            return to_route('profile.edit');
        }

        // Consumers matched this account on email at first sign-in and cached
        // it; leaving them stale is how two apps end up disagreeing about who
        // someone is.
        $user->refresh();

        $notifyClients->handle($user, LogoutNotification::EVENT_USER_UPDATED, null, [
            'name' => $user->name,
            'email' => $user->email,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Email address updated.')]);

        return to_route('profile.edit');
    }

    public function cancel(Request $request): RedirectResponse
    {
        $this->currentUser($request)->forceFill([
            'pending_email' => null,
            'pending_email_token' => null,
            'pending_email_expires_at' => null,
        ])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Pending email change cancelled.')]);

        return to_route('profile.edit');
    }
}
