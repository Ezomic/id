<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Laravel\Fortify\Fortify;
use Laravel\Passport\Guards\TokenGuard;

class FortifyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Passwordless: users have no password. Fortify's credential login is disabled;
        // sign-in happens through email login codes or passkeys only.
        Fortify::authenticateUsing(fn () => null);

        Fortify::loginView(fn (Request $request) => Inertia::render('auth/Login', [
            'status' => $request->session()->get('status'),
            'email' => $request->session()->get('login_email'),
            'codeSent' => (bool) $request->session()->get('code_sent'),
            'recoveryMode' => (bool) $request->session()->get('recovery_mode'),
        ]));

        $this->configureRateLimiting();
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->string('email')->value()).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        // Keyed on the calling client, not the IP: the callers are seven
        // server-side apps, so an IP limit would meter the droplet rather than
        // the client, and one compromised secret would be bounded by nothing.
        RateLimiter::for('portal-lookups', function (Request $request) {
            $guard = Auth::guard('api');
            $client = $guard instanceof TokenGuard ? $guard->client() : null;
            $clientKey = $client?->getKey();
            $key = is_scalar($clientKey) ? (string) $clientKey : (string) $request->ip();

            return Limit::perMinute(60)->by('portal-lookups:'.$key);
        });

        RateLimiter::for('passkeys', function (Request $request) {
            $identifier = $request->string('credential.id')->value() ?: $request->session()->getId();

            return Limit::perMinute(10)->by($identifier.'|'.$request->ip());
        });
    }
}
