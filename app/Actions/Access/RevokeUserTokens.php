<?php

declare(strict_types=1);

namespace App\Actions\Access;

use App\Models\Application;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Passport\AuthCode;
use Laravel\Passport\RefreshToken;
use Laravel\Passport\Token;

final class RevokeUserTokens
{
    /**
     * Detaching a pivot row does nothing to credentials already issued. Access
     * tokens are good for 15 minutes and refresh tokens for 30 days, so without
     * this the consumer app keeps minting fresh tokens for a month after the
     * grant is gone.
     *
     * Unredeemed authorization codes go too: one of those is a token waiting
     * to happen.
     *
     * @param  list<int>|null  $applicationIds  Null revokes every client.
     * @return int The number of access tokens destroyed.
     */
    public function handle(User $user, ?array $applicationIds = null): int
    {
        $clientIds = $applicationIds === null ? null : $this->clientIdsFor($applicationIds);

        if ($clientIds === []) {
            return 0;
        }

        $tokenIds = $user->tokens()
            ->when($clientIds !== null, fn (Builder $query) => $query->whereIn('client_id', $clientIds))
            ->pluck('id')
            ->all();

        AuthCode::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->when($clientIds !== null, fn (Builder $query) => $query->whereIn('client_id', $clientIds))
            ->delete();

        if ($tokenIds === []) {
            return 0;
        }

        RefreshToken::query()->whereIn('access_token_id', $tokenIds)->delete();
        Token::query()->whereIn('id', $tokenIds)->delete();

        return count($tokenIds);
    }

    /**
     * @param  list<int>  $applicationIds
     * @return list<string>
     */
    private function clientIdsFor(array $applicationIds): array
    {
        if ($applicationIds === []) {
            return [];
        }

        $clientIds = Application::query()
            ->whereIn('id', $applicationIds)
            ->whereNotNull('oauth_client_id')
            ->pluck('oauth_client_id')
            ->all();

        return array_values(array_map(
            fn (mixed $id): string => is_scalar($id) ? (string) $id : '',
            $clientIds,
        ));
    }
}
