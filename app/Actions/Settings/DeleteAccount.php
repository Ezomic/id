<?php

declare(strict_types=1);

namespace App\Actions\Settings;

use App\Actions\Access\RevokeUserTokens;
use App\Actions\Auth\NotifyClientsOfEvent;
use App\Actions\Auth\NotifyClientsOfLogout;
use App\Models\LogoutNotification;
use App\Models\User;

final class DeleteAccount
{
    public function __construct(
        private readonly NotifyClientsOfLogout $notifyLogout,
        private readonly NotifyClientsOfEvent $notifyClients,
        private readonly RevokeUserTokens $revokeUserTokens,
    ) {}

    /**
     * Who to tell is only known while the user row and its grants exist, so
     * the notifications are written first and delivered after the response,
     * the same as any other sign-out. See ID-90.
     *
     * Every session is signed out, not only the one deleting the account, and
     * every app the user could reach hears access.revoked, since an app can
     * hold API tokens for someone who never opened it from this browser.
     */
    public function handle(User $user): void
    {
        $reachable = $user->accessibleApplicationIds()->all();

        $this->notifyLogout->deliverAfterResponse($this->notifyLogout->handle($user));
        $this->notifyClients->handle($user, LogoutNotification::EVENT_ACCESS_REVOKED, $reachable);

        // The users row goes away but Passport rows are not cascaded, so without
        // this the deleted account's tokens stay valid at every consumer app.
        $this->revokeUserTokens->handle($user);

        $user->delete();
    }
}
