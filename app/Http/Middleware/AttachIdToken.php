<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\IdTokenIssuer;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\UnencryptedToken;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class AttachIdToken
{
    public function __construct(private readonly IdTokenIssuer $issuer) {}

    /**
     * Passport issues access tokens, not ID tokens, and league/oauth2-server
     * builds the response body itself. Adding the claim here is the seam that
     * does not require forking either.
     *
     * Only clients that ask for `openid` get one, so nothing changes for the
     * seven consumers that do not.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->routeIs('passport.token') || $response->getStatusCode() !== 200) {
            return $response;
        }

        try {
            $idToken = $this->mint($request, $response);
        } catch (Throwable) {
            // A malformed or unexpected token response must not turn a working
            // sign-in into a 500.
            return $response;
        }

        return $idToken === null ? $response : $this->merge($response, $idToken);
    }

    private function mint(Request $request, Response $response): ?string
    {
        $body = json_decode((string) $response->getContent(), true);

        if (! is_array($body) || ! is_string($body['access_token'] ?? null)) {
            return null;
        }

        $accessToken = $body['access_token'];

        if ($accessToken === '') {
            return null;
        }

        $token = (new Parser(new JoseEncoder))->parse($accessToken);

        if (! $token instanceof UnencryptedToken) {
            return null;
        }

        $scopes = $token->claims()->get('scopes', []);
        $scopes = is_array($scopes)
            ? array_values(array_map(fn (mixed $scope): string => is_scalar($scope) ? (string) $scope : '', $scopes))
            : [];

        if (! in_array('openid', $scopes, true)) {
            return null;
        }

        $subject = $token->claims()->get('sub');
        $audience = $token->claims()->get('aud', []);
        $user = is_scalar($subject) ? User::find((int) $subject) : null;

        if (! $user instanceof User) {
            return null;
        }

        $client = is_array($audience) ? ($audience[0] ?? '') : $audience;

        return $this->issuer->issue(
            $user,
            is_scalar($client) ? (string) $client : '',
            $scopes,
            $this->nonceFor($request),
        );
    }

    private function nonceFor(Request $request): ?string
    {
        $code = $request->input('code');

        if (! is_string($code) || $code === '') {
            return null;
        }

        $key = 'oidc.nonce:'.hash('sha256', $code);
        $nonce = Cache::pull($key);

        return is_string($nonce) ? $nonce : null;
    }

    private function merge(Response $response, string $idToken): Response
    {
        $body = json_decode((string) $response->getContent(), true);
        $body = is_array($body) ? $body : [];
        $body['id_token'] = $idToken;

        $response->setContent(json_encode($body, JSON_THROW_ON_ERROR));

        return $response;
    }
}
