<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\AuthorizedClient;
use App\Models\User;
use App\Services\SsoSessionId;
use Closure;
use Illuminate\Http\Request;
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

        if (! $this->issuedACode($response)) {
            return $response;
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
    private function issuedACode(Response $response): bool
    {
        if (! $response->isRedirect()) {
            return false;
        }

        parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);

        return isset($query['code']);
    }
}
