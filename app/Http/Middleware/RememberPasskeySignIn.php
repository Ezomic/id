<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RememberPasskeySignIn
{
    /**
     * A browser stays signed in until its user signs out, so every way in
     * remembers the device. The passkeys package leaves that to a `remember`
     * flag from the client and defaults it to off; setting it here means no
     * client can leave it out.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs('passkey.login')) {
            $request->merge(['remember' => true]);
        }

        return $next($request);
    }
}
