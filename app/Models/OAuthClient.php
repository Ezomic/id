<?php

namespace App\Models;

use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Passport\Client as PassportClient;
use Laravel\Passport\Scope;

/**
 * Passport casts these columns to arrays but does not declare them.
 *
 * @property list<string> $redirect_uris
 * @property list<string>|null $scopes
 */
class OAuthClient extends PassportClient
{
    public const ESTATE_READ = 'estate:read';

    /**
     * All clients here are first-party workflow apps we own, so the consent
     * screen is skipped and authorization is granted immediately.
     *
     * @param  Scope[]  $scopes
     */
    public function skipsAuthorization(Authenticatable $user, array $scopes): bool
    {
        return true;
    }

    /**
     * Every workflow app's client has client_credentials for the portal
     * switcher, and Passport lets a client with no listed scopes be issued any
     * of them. estate:read lists every app and every grant, so it is only
     * issued to a client that was registered with it by name. The wildcard
     * would imply it, so it gets the same treatment.
     */
    public function hasScope(string $scope): bool
    {
        if ($scope === self::ESTATE_READ || $scope === '*') {
            return in_array($scope, $this->scopes ?? [], true);
        }

        return parent::hasScope($scope);
    }
}
