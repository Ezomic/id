<?php

namespace App\Providers;

use App\Models\User;
use App\Services\QrLoginPolicy;
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
    public const LOGIN_ATTEMPTS_PER_EMAIL_PER_HOUR = 30;

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
            'qrLoginEnabled' => app(QrLoginPolicy::class)->enabled(),
        ]));

        $this->configureRateLimiting();
    }

    private function configureRateLimiting(): void
    {
        // The per-email limit ignores the IP, so rotating addresses cannot buy
        // more guesses, or more fresh codes to guess at, for one account.
        RateLimiter::for('login', function (Request $request) {
            $email = Str::transliterate(Str::lower($request->string('email')->value()));
            $limits = [Limit::perMinute(5)->by($email.'|'.$request->ip())];

            if ($email !== '') {
                $limits[] = Limit::perHour(self::LOGIN_ATTEMPTS_PER_EMAIL_PER_HOUR)->by('login-email|'.$email);
            }

            return $limits;
        });

        // Keyed on the calling client, not the IP: the callers are seven
        // server-side apps, so an IP limit would meter the droplet rather than
        // the client, and one compromised secret would be bounded by nothing.
        RateLimiter::for('portal-lookups', fn (Request $request) => Limit::perMinute(60)->by('portal-lookups:'.$this->clientKey($request)));

        RateLimiter::for('estate-reads', fn (Request $request) => Limit::perMinute(30)->by('estate-reads:'.$this->clientKey($request)));

        // Creating writes a row, so it is bounded by address. Polling is one
        // request every two seconds per open tab.
        RateLimiter::for('qr-create', fn (Request $request) => Limit::perMinute(10)->by('qr-create|'.$request->ip()));

        RateLimiter::for('qr-poll', fn (Request $request) => Limit::perMinute(60)->by('qr-poll|'.$request->session()->getId().'|'.$request->ip()));

        RateLimiter::for('qr-redeem', fn (Request $request) => Limit::perMinute(10)->by('qr-redeem|'.$request->session()->getId().'|'.$request->ip()));

        RateLimiter::for('qr-approve', fn (Request $request) => Limit::perMinute(10)->by('qr-approve|'.($request->user() instanceof User ? $request->user()->id : 'guest').'|'.$request->ip()));

        // A short code is 40 bits and lives two minutes; this is what makes
        // guessing one hopeless. Per address and per session, so rotating
        // addresses does not reset the count.
        RateLimiter::for('qr-code', fn (Request $request) => [
            Limit::perMinute(10)->by('qr-code|'.$request->ip()),
            Limit::perMinute(10)->by('qr-code|'.$request->session()->getId()),
        ]);

        RateLimiter::for('passkeys', function (Request $request) {
            $identifier = $request->string('credential.id')->value() ?: $request->session()->getId();

            return Limit::perMinute(10)->by($identifier.'|'.$request->ip());
        });
    }

    private function clientKey(Request $request): string
    {
        $guard = Auth::guard('api');
        $client = $guard instanceof TokenGuard ? $guard->client() : null;
        $clientKey = $client?->getKey();

        return is_scalar($clientKey) ? (string) $clientKey : (string) $request->ip();
    }
}
