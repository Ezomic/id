<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\OidcKeys;
use Illuminate\Http\JsonResponse;

class OidcDiscoveryController extends Controller
{
    /**
     * Until now the contract with consumers was bespoke: a userinfo endpoint
     * only thijssensoftware/id-client knew how to speak. This is the same
     * information in a form anything can read.
     */
    public function configuration(): JsonResponse
    {
        $url = config('app.url');
        $issuer = rtrim(is_string($url) ? $url : '', '/');

        return response()->json([
            'issuer' => $issuer,
            'authorization_endpoint' => $issuer.'/oauth/authorize',
            'token_endpoint' => $issuer.'/oauth/token',
            'userinfo_endpoint' => $issuer.'/api/userinfo',
            'jwks_uri' => $issuer.'/.well-known/jwks.json',
            'end_session_endpoint' => $issuer.'/logout',
            'scopes_supported' => ['openid', 'identity', 'estate'],
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token', 'client_credentials'],
            'subject_types_supported' => ['public'],
            'id_token_signing_alg_values_supported' => ['RS256'],
            'token_endpoint_auth_methods_supported' => ['client_secret_post', 'client_secret_basic'],
            'code_challenge_methods_supported' => ['S256'],
            'claims_supported' => ['sub', 'name', 'email', 'email_verified', 'nonce', 'aud', 'iss', 'exp', 'iat'],
        ]);
    }

    public function jwks(OidcKeys $keys): JsonResponse
    {
        return response()->json($keys->jwks());
    }
}
