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
     * leaving a group, or the user being taken off an app's access list. The
     * caller passes what the user could reach before the change, and whatever
     * is missing from it now is lost.
     *
     * That has to come from the grants rather than from live tokens: id-client
     * never refreshes, so its 15 minute token is purged a week later, and signing
     * out at ID deletes it outright. Asking the tokens told nobody in exactly
     * the case where the app still holds API tokens for the user. See ID-89.
     *
     * A token for an app the user cannot reach any more goes too, however that
     * came about.
     *
     * @param  array<int, int>  $reachableBefore  accessibleApplicationIds() from before the change.
     * @return int The number of access tokens destroyed.
     */
    public function handle(User $user, array $reachableBefore): int
    {
        $lost = array_values(array_unique([
            ...array_diff($reachableBefore, $user->accessibleApplicationIds()->all()),
            ...$this->tokensWithoutAccess($user),
        ]));

        if ($lost === []) {
            return 0;
        }

        // Revoking the tokens stops the app refreshing; telling it is what ends
        // the local session the user is still sitting in.
        $this->notifyClients->handle($user, LogoutNotification::EVENT_ACCESS_REVOKED, $lost);

        return $this->revokeUserTokens->handle($user, $lost);
    }

    /**
     * @return list<int>
     */
    private function tokensWithoutAccess(User $user): array
    {
        $clientIds = $user->tokens()->pluck('client_id')->unique()->values()->all();

        if ($clientIds === []) {
            return [];
        }

        return array_values(
            Application::query()
                ->whereIn('oauth_client_id', $clientIds)
                ->get()
                ->reject(fn (Application $application): bool => $application->active && $user->canAccess($application))
                ->map(fn (Application $application): int => $application->id)
                ->all()
        );
    }
}
