<?php

declare(strict_types=1);

namespace App\Actions\Access;

use App\Actions\Auth\NotifyClientsOfEvent;
use App\Models\Application;
use App\Models\LogoutNotification;
use App\Models\User;

final class RevokeTokensForLostAccess
{
    public function __construct(
        private readonly RevokeUserTokens $revokeUserTokens,
        private readonly NotifyClientsOfEvent $notifyClients,
    ) {}

    /**
     * Access can be lost through a direct revoke, a group losing an app, a user
     * leaving a group, or an app being deactivated. Rather than reason about
     * which of those happened, re-derive effective access and tear down the
     * tokens that no longer have a grant behind them.
     *
     * @return int The number of access tokens destroyed.
     */
    public function handle(User $user): int
    {
        $clientIds = $user->tokens()->pluck('client_id')->unique()->values()->all();

        if ($clientIds === []) {
            return 0;
        }

        $lost = array_values(
            Application::query()
                ->whereIn('oauth_client_id', $clientIds)
                ->get()
                ->reject(fn (Application $application): bool => $application->active && $user->canAccess($application))
                ->map(fn (Application $application): int => $application->id)
                ->all()
        );

        if ($lost === []) {
            return 0;
        }

        // Revoking the tokens stops the app refreshing; telling it is what ends
        // the local session the user is still sitting in.
        $this->notifyClients->handle($user, LogoutNotification::EVENT_ACCESS_REVOKED, $lost);

        return $this->revokeUserTokens->handle($user, $lost);
    }
}
