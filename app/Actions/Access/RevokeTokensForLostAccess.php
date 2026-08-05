<?php

declare(strict_types=1);

namespace App\Actions\Access;

use App\Models\Application;
use App\Models\User;

final class RevokeTokensForLostAccess
{
    public function __construct(private readonly RevokeUserTokens $revokeUserTokens) {}

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

        return $lost === [] ? 0 : $this->revokeUserTokens->handle($user, $lost);
    }
}
