<?php

namespace App\Concerns;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

trait InteractsWithCurrentUser
{
    /**
     * The authenticated user, typed. Every caller sits behind the auth
     * middleware, so a null here is a routing mistake rather than a
     * reachable state.
     */
    protected function currentUser(?Request $request = null): User
    {
        $user = $request?->user() ?? Auth::user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
