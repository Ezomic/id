<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Portal\LaunchableAppsForUser;
use App\Http\Controllers\Controller;
use App\Models\Application;
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
            return response()->json(['applications' => []]);
        }

        return response()->json([
            'applications' => $launchableApps->handle($user),
        ]);
    }

    /**
     * Only the configured app switcher's client may call this. The valid
     * client-credentials token is matched to its registered application by slug.
     */
    private function authorizeCaller(): void
    {
        $guard = Auth::guard('api');
        $client = $guard instanceof TokenGuard ? $guard->client() : null;

        abort_unless(
            $client !== null && Application::query()
                ->where('oauth_client_id', $client->getKey())
                ->where('slug', config('services.portal.switcher_client_slug'))
                ->where('active', true)
                ->exists(),
            Response::HTTP_FORBIDDEN,
        );
    }
}
