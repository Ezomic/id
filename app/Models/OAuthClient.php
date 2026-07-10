<?php

namespace App\Models;

use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Passport\Client as PassportClient;
use Laravel\Passport\Scope;

class OAuthClient extends PassportClient
{
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
}
