<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Contracts\LogoutResponse;

class SignOutController extends Controller
{
    /**
     * Fortify's sign-out runs SessionGuard::logout(), which cycles the user's
     * one remember token and so signs out every other remembered browser as
     * well. Signing out here ends this browser only. "Sign out all other
     * sessions" and an admin's "Sign out everywhere" still end the rest.
     */
    public function __invoke(Request $request): LogoutResponse
    {
        Auth::logoutCurrentDevice();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return app(LogoutResponse::class);
    }
}
