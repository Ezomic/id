<?php

namespace App\Providers;

use App\Listeners\RecordSignIn;
use App\Models\OAuthClient;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configurePassport();

        Event::listen(Login::class, RecordSignIn::class);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        // Preloaded-asset Link headers on pages with many chunks (e.g. /login)
        // overflow nginx's fastcgi header buffer and 502. Disable preloading;
        // the modulepreload tags are unnecessary behind the Vite manifest.
        Vite::usePreloadTagAttributes(false);
    }

    protected function configurePassport(): void
    {
        Passport::useClientModel(OAuthClient::class);
        Passport::tokensExpireIn(now()->addMinutes(15));
        Passport::refreshTokensExpireIn(now()->addDays(30));

        // Every client is a first-party workflow app that skips authorization,
        // so the consent screen is never rendered. Binding it keeps the
        // authorize endpoint resolvable and makes the invariant explicit.
        Passport::authorizationView(fn (): never => abort(
            500,
            'The consent screen is disabled; all clients are first-party.',
        ));
    }
}
