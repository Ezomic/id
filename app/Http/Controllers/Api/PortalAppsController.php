<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Portal\LaunchableAppsForUser;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Passport\Guards\TokenGuard;
use Symfony\Component\HttpFoundation\Response;

class PortalAppsController extends Controller
{
    /**
     * Machine-to-machine: given a user's email, return the apps that user can
     * launch. Called by the app switcher's own client (client-credentials) to
     * render its in-app portal.
     */
    public function __invoke(Request $request, LaunchableAppsForUser $launchableApps): JsonResponse
    {
        $this->authorizeCaller();

        $data = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if ($user === null) {
            return response()->json(['applications' => [], 'categories' => []]);
        }

        return response()->json($launchableApps->handle($user));
    }

    /**
     * Every OAuth client registered on this ID is a trusted first-party app, so
     * any valid client-credentials token may query the switcher on a user's
     * behalf. The `client` middleware already validated the token.
     */
    private function authorizeCaller(): void
    {
        $guard = Auth::guard('api');
        $client = $guard instanceof TokenGuard ? $guard->client() : null;

        abort_if($client === null, Response::HTTP_FORBIDDEN);
    }
}
