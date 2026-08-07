<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\Auth\NotifyClientsOfLogout;
use App\Models\User;
use App\Services\SsoSessionId;
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
     * Scoped to this one session, so signing out on a laptop does not end the
     * same user's sessions on their phone.
     */
    public function handle(Logout $event): void
    {
        $user = $event->user;
        $sessionId = $this->ssoSessionId->existing($this->request);

        if (! $user instanceof User || $sessionId === null) {
            return;
        }

        $this->notifyClients->deliverAfterResponse(
            $this->notifyClients->handle($user, $sessionId),
        );
    }
}
