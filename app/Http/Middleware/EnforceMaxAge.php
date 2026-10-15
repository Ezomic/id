<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\OAuthClient;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Laravel\Passport\ClientRepository;
use Symfony\Component\HttpFoundation\Response;

class EnforceMaxAge
{
    /**
     * Below this the round trip through the re-authentication screen could
     * outlast the allowance and send the user round in circles.
     */
    public const MIN_SECONDS = 30;

    public const MAX_SECONDS = 86400;

    public const ATTRIBUTE = 'oidc.auth_time';

    public function __construct(private readonly ClientRepository $clients) {}

    /**
     * OIDC `max_age` for the authorize endpoint: the app asks that the user
     * authenticated no longer ago than that. `prompt=login` is how the spec
     * forces it, but Passport meets that by logging the user out of ID, which
     * signs them out of every app. Re-using the confirmation that guards ID's
     * own sensitive pages gets the same proof without ending the session.
     *
     * Only a confirmation counts, never the sign-in itself: a session that
     * merely exists, or a sign-in by emailed code, is exactly what a step-up
     * exists to see past. The verified time is handed on through the request so
     * the ID token can carry it as `auth_time`.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->routeIs('passport.authorizations.authorize') || ! $request->query->has('max_age')) {
            return $next($request);
        }

        $maxAge = $this->parse($request->query->all()['max_age']);

        if ($maxAge === null) {
            return $this->reject($request, 'invalid_request', 'max_age must be a whole number of seconds between '.self::MIN_SECONDS.' and '.self::MAX_SECONDS.'.');
        }

        if (! $this->requestsOpenid($request)) {
            return $this->reject($request, 'invalid_request', 'max_age needs the openid scope, because auth_time is delivered in the ID token.');
        }

        if ($request->user() === null) {
            return $next($request);
        }

        $authTime = $this->confirmedAt($request, $maxAge);

        if ($authTime === null) {
            return $this->prompts($request, 'none')
                ? $this->reject($request, 'login_required', 'The user has not re-authenticated recently enough.')
                : $this->reauthenticate($request);
        }

        $request->attributes->set(self::ATTRIBUTE, $authTime);

        return $next($request);
    }

    private function parse(mixed $value): ?int
    {
        if (! is_string($value) || ! preg_match('/^[0-9]{1,6}$/', $value)) {
            return null;
        }

        $seconds = (int) $value;

        return $seconds >= self::MIN_SECONDS && $seconds <= self::MAX_SECONDS ? $seconds : null;
    }

    private function confirmedAt(Request $request, int $maxAge): ?int
    {
        $confirmedAt = $request->session()->get('auth.password_confirmed_at');

        if (! is_int($confirmedAt) && ! (is_string($confirmedAt) && ctype_digit($confirmedAt))) {
            return null;
        }

        $confirmedAt = (int) $confirmedAt;
        $age = Date::now()->unix() - $confirmedAt;

        return $age >= 0 && $age <= $maxAge ? $confirmedAt : null;
    }

    private function requestsOpenid(Request $request): bool
    {
        $scope = $request->query('scope');
        $scopes = is_string($scope) ? preg_split('/\s+/', $scope, -1, PREG_SPLIT_NO_EMPTY) : [];

        return in_array('openid', $scopes ?: [], true);
    }

    private function prompts(Request $request, string $value): bool
    {
        $prompt = $request->query('prompt');

        return is_string($prompt) && in_array($value, preg_split('/\s+/', $prompt, -1, PREG_SPLIT_NO_EMPTY) ?: [], true);
    }

    private function reauthenticate(Request $request): Response
    {
        $request->session()->put('url.intended', $request->fullUrl());

        return redirect()->route('reauthenticate.show');
    }

    /**
     * Errors go back to the app only when both the client and the redirect URI
     * are ones ID registered. Otherwise this would be an open redirect for
     * anyone who can type a query string, so those get a plain 400.
     */
    private function reject(Request $request, string $error, string $description): Response
    {
        $redirectUri = $this->registeredRedirectUri($request);

        abort_if($redirectUri === null, Response::HTTP_BAD_REQUEST, $description);

        $query = ['error' => $error, 'error_description' => $description];
        $state = $request->query('state');

        if (is_string($state) && $state !== '') {
            $query['state'] = $state;
        }

        return redirect()->away($redirectUri.(str_contains($redirectUri, '?') ? '&' : '?').http_build_query($query));
    }

    private function registeredRedirectUri(Request $request): ?string
    {
        $clientId = $request->query('client_id');
        $redirectUri = $request->query('redirect_uri');

        if (! is_string($clientId) || $clientId === '' || ! is_string($redirectUri) || $redirectUri === '') {
            return null;
        }

        $client = $this->clients->findActive($clientId);

        return $client instanceof OAuthClient && in_array($redirectUri, $client->redirect_uris, true)
            ? $redirectUri
            : null;
    }
}
