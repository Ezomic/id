<?php

use App\Actions\Auth\RecordFailedSignIn;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequireRecentAuthentication;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Laravel\Passkeys\Exceptions\InvalidPasskeyException;
use Laravel\Passport\Http\Middleware\EnsureClientIsResourceOwner;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Only APP_URL's own name, no subdomains. The step-up return path
        // (ID-97) trusts a Referer that matches the request's host, which means
        // nothing if the host is whatever the caller sent. Anchored, because
        // Symfony reads each entry as an unanchored pattern; a closure, because
        // config is not loaded yet when this runs.
        $middleware->trustHosts(
            at: fn (): array => ['^'.preg_quote((string) parse_url(config()->string('app.url'), PHP_URL_HOST)).'$'],
            subdomains: false,
        );

        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'admin' => EnsureUserIsAdmin::class,
            'client' => EnsureClientIsResourceOwner::class,
            'reauth' => RequireRecentAuthentication::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // A failed passkey assertion throws rather than firing an auth event, so
        // this is the only place it can be caught. The credential does not
        // identify a user when verification fails, hence the null.
        $exceptions->report(function (InvalidPasskeyException $e): void {
            app(RecordFailedSignIn::class)->handle(null, 'passkey');
        });
    })->create();
