<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Application;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceApplicationScopes
{
    /**
     * Passport validates a requested scope against the globally registered set,
     * which says nothing about whether *this* client may ask for it. Without
     * this, declaring an allowlist would be decoration.
     *
     * Applications registered before scoping have a null allowlist and are left
     * alone, so nothing that works today stops working.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->routeIs('passport.authorizations.authorize')) {
            return $next($request);
        }

        $clientId = $request->query('client_id');
        $requested = preg_split('/\s+/', (string) $request->query('scope'), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (! is_string($clientId) || $requested === []) {
            return $next($request);
        }

        $application = Application::where('oauth_client_id', $clientId)->first();

        if (! $application instanceof Application || ! $application->scopesAreEnforced()) {
            return $next($request);
        }

        foreach ($requested as $scope) {
            abort_unless(
                $application->allowsScope($scope),
                Response::HTTP_FORBIDDEN,
                "This application may not request the [{$scope}] scope.",
            );
        }

        return $next($request);
    }
}
