<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Portal\LaunchableAppsForUser;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PortalAppsController extends Controller
{
    /**
     * Machine-to-machine: given a user's email, return the apps that user can
     * launch. Called by trusted first-party clients (client-credentials) to
     * render their in-app portal / app switcher.
     */
    public function __invoke(Request $request, LaunchableAppsForUser $launchableApps): JsonResponse
    {
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
}
