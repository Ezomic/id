<?php

declare(strict_types=1);

namespace App\Actions\Access;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

final class ForgetRememberedBrowsers
{
    /**
     * Deleting a sessions row only ends the session: every sign-in also sets a
     * remember-me cookie, and on the browser's next visit that cookie quietly
     * starts a new one. Laravel keeps a single remember token per user, so
     * cycling it is the only way to end a remembered browser, and it ends all
     * of them at once.
     *
     * @param  Request|null  $except  The browser a user is acting from while
     *                                signing out their others. It is given a
     *                                cookie for the new token, as Laravel's own
     *                                logoutOtherDevices() does. Null ends every
     *                                remembered browser.
     */
    public function handle(User $user, ?Request $except = null): void
    {
        $user->forceFill(['remember_token' => Str::random(60)])->save();

        if ($except !== null) {
            $this->rememberAgain($user, $except);
        }
    }

    private function rememberAgain(User $user, Request $request): void
    {
        $guard = Auth::guard('web');
        $name = $guard->getRecallerName();

        // A browser that signed in without remember-me is not upgraded to it.
        if (! $request->cookies->has($name)) {
            return;
        }

        // The value and lifetime SessionGuard gives the cookie at sign-in. The
        // guard keeps both behind protected methods, and the only public path
        // to them is logging in again, which would record a new sign-in.
        Cookie::queue(
            $name,
            $user->id.'|'.$user->remember_token.'|'.$guard->hashPasswordForCookie($user->getAuthPassword()),
            Config::integer('auth.guards.web.remember', 576000),
        );
    }
}
