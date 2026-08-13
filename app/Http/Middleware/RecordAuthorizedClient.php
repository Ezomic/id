<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\AuthorizedClient;
use App\Models\User;
use App\Services\SsoSessionId;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class RecordAuthorizedClient
{
    public function __construct(private readonly SsoSessionId $ssoSessionId) {}

    /**
     * Every client is first-party and skips consent, so a successful authorize
     * is a plain redirect carrying a code. Noting it here is what lets logout
     * know which consumers this session actually signed in to.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->routeIs('passport.authorizations.authorize')) {
            return $response;
        }

        $user = $request->user();
        $clientId = $request->query('client_id');

        if (! $user instanceof User || ! is_string($clientId) || $clientId === '') {
            return $response;
        }

        $code = $this->issuedCode($response);

        if ($code === null) {
            return $response;
        }

        // The nonce belongs to the authorize request, but the ID token is
        // minted at the token endpoint, which is a separate server-to-server
        // call with no session. Parking it against the code is what carries it
        // across; the code is single use and short lived, so is this.
        $nonce = $request->query('nonce');

        if (is_string($nonce) && $nonce !== '') {
            Cache::put('oidc.nonce:'.hash('sha256', $code), $nonce, now()->addMinutes(10));
        }

        AuthorizedClient::query()->updateOrCreate(
            [
                'sso_session_id' => $this->ssoSessionId->for($request),
                'oauth_client_id' => $clientId,
            ],
            ['user_id' => $user->id],
        );

        return $response;
    }

    /**
     * A denied or failed authorize redirects with an `error` instead, and that
     * is not a sign-in worth remembering.
     */
    private function issuedCode(Response $response): ?string
    {
        if (! $response->isRedirect()) {
            return null;
        }

        parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);

        $code = $query['code'] ?? null;

        return is_string($code) && $code !== '' ? $code : null;
    }
}
