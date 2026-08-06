<?php

declare(strict_types=1);

namespace App\Actions\Access;

use Laravel\Passport\AuthCode;
use Laravel\Passport\Client;
use Laravel\Passport\RefreshToken;
use Laravel\Passport\Token;

final class RevokeClientTokens
{
    /**
     * Every credential issued under a client, for every user. Used when the
     * client itself stops being trustworthy (a rotated or leaked secret, or a
     * disabled app), as opposed to RevokeUserTokens which scopes to one person.
     *
     * @return int The number of access tokens destroyed.
     */
    public function handle(Client $client): int
    {
        $tokenIds = Token::query()->where('client_id', $client->getKey())->pluck('id')->all();

        AuthCode::query()->where('client_id', $client->getKey())->delete();

        if ($tokenIds === []) {
            return 0;
        }

        RefreshToken::query()->whereIn('access_token_id', $tokenIds)->delete();
        Token::query()->whereIn('id', $tokenIds)->delete();

        return count($tokenIds);
    }
}
