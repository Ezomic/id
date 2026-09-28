<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Exception\RequestExceptionInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

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

        $request->session()->put('url.intended', $this->returnTo($request));

        return redirect()->route('reauthenticate.show');
    }

    /**
     * Both confirmation paths follow the intended URL with a GET, so a guarded
     * PUT, POST or DELETE cannot be its own destination: it would answer 405,
     * or land on whatever page shares its path. The page the action was taken
     * from is where the admin can repeat it.
     */
    private function returnTo(Request $request): string
    {
        if ($request->isMethod('GET')) {
            return $request->fullUrl();
        }

        // The Referer rather than the session's previous URL: Inertia visits
        // are XHRs, which Laravel never records as the previous URL, so after
        // any in-app navigation that names the last full page load instead of
        // the page the admin is on. The header is the client's to write, so it
        // only counts when it names a page of this app. No narrower list: any
        // such page is a plain link away for anyone, so landing on one grants
        // nothing a link would not.
        $referer = $request->headers->get('referer');

        if ($referer !== null && $this->isPageOfThisApp($request, $referer)) {
            return $referer;
        }

        return route('dashboard');
    }

    private function isPageOfThisApp(Request $request, string $url): bool
    {
        // A prefix match through the slash that ends the host, so no URL
        // parser decides where the host ends: userinfo, a port, a backslash or
        // a longer lookalike host all break the match instead of hiding in it.
        // The request's own origin rather than APP_URL: the redirect to the
        // prompt and every other URL this app builds follow the same Host
        // header, so a forged Host is only stopped in front of the app, where
        // the web server refuses names it does not serve.
        if (! str_starts_with($url, $request->getSchemeAndHttpHost().'/')) {
            return false;
        }

        try {
            Route::getRoutes()->match(Request::create($url));
        } catch (HttpExceptionInterface|RequestExceptionInterface) {
            return false;
        }

        return true;
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
