<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\Auth\NotifyClientsOfLogout;
use App\Models\User;
use App\Services\SsoSessionId;
use Illuminate\Auth\Events\CurrentDeviceLogout;
use Illuminate\Auth\Events\Logout;
use Illuminate\Http\Request;

class PropagateLogout
{
    public function __construct(
        private readonly Request $request,
        private readonly SsoSessionId $ssoSessionId,
        private readonly NotifyClientsOfLogout $notifyClients,
    ) {}

    /**
     * Signing out of ID used to end the ID session and nothing else: each
     * consumer holds its own session, established once at the OAuth callback,
     * and never asks ID anything again. Tell them.
     *
     * Scoped to this browser, across its remember-me restores, so signing out
     * on a laptop does not end the same user's sessions on their phone. The
     * browser's SSO session ends with it. Signing out of ID fires
     * CurrentDeviceLogout; account deletion and Passport's prompt=login still
     * fire Logout. Each fires one or the other, never both.
     */
    public function handle(Logout|CurrentDeviceLogout $event): void
    {
        $user = $event->user;

        if (! $user instanceof User) {
            return;
        }

        $sessionId = $this->ssoSessionId->existing($this->request, $user);

        if ($sessionId === null) {
            return;
        }

        $this->notifyClients->deliverAfterResponse(
            $this->notifyClients->handle($user, $sessionId),
        );

        $this->ssoSessionId->forget();
    }
}
