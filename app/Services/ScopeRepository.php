<?php

declare(strict_types=1);

namespace App\Services;

use Laravel\Passport\Bridge\ScopeRepository as PassportScopeRepository;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;

class ScopeRepository extends PassportScopeRepository
{
    /**
     * A client_credentials token has the client as its subject, not a person,
     * so the scopes that describe a signed-in user mean nothing on it. Left in,
     * openid made the token endpoint mint an ID token for whichever user id the
     * client's UUID happened to cast to.
     */
    private const USER_SCOPES = ['openid', 'identity', 'estate'];

    /**
     * @param  ScopeEntityInterface[]  $scopes
     * @return ScopeEntityInterface[]
     */
    public function finalizeScopes(
        array $scopes,
        string $grantType,
        ClientEntityInterface $clientEntity,
        ?string $userIdentifier = null,
        ?string $authCodeId = null,
    ): array {
        $scopes = parent::finalizeScopes($scopes, $grantType, $clientEntity, $userIdentifier, $authCodeId);

        if ($grantType !== 'client_credentials') {
            return $scopes;
        }

        return array_values(array_filter(
            $scopes,
            fn (ScopeEntityInterface $scope): bool => ! in_array($scope->getIdentifier(), self::USER_SCOPES, true),
        ));
    }
}
