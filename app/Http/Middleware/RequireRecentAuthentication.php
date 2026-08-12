<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Symfony\Component\HttpFoundation\Response;

class RequireRecentAuthentication
{
    /**
     * Minutes a confirmation stays good for. Long enough that a burst of admin
     * work is not a burst of prompts, short enough that a session left open on
     * a borrowed laptop is not a standing licence to rotate secrets.
     */
    public const WINDOW_MINUTES = 15;

    /**
     * A single live session could rotate an OAuth client secret, disable an
     * application, force any user out of everything and delete an account, with
     * no second check anywhere. Fortify ships `password.confirm` for exactly
     * this and it is unusable here, because there are no passwords.
     *
     * Reuses the framework's confirmation timestamp so Fortify's own passkey
     * confirmation endpoint counts, and adds a recovery code as the fallback
     * factor. Deliberately never an emailed code: an attacker holding the
     * session very likely holds the inbox that produced it.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->recentlyConfirmed($request)) {
            return $next($request);
        }

        $request->session()->put('url.intended', $request->fullUrl());

        return redirect()->route('reauthenticate.show');
    }

    private function recentlyConfirmed(Request $request): bool
    {
        $confirmedAt = $request->session()->get('auth.password_confirmed_at');

        if (! is_int($confirmedAt) && ! is_numeric($confirmedAt)) {
            return false;
        }

        return Date::now()->unix() - (int) $confirmedAt < self::WINDOW_MINUTES * 60;
    }
}
