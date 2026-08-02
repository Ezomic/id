<?php

namespace App\Http\Controllers\Api;

use App\Actions\Access\AutoGrantApplicationAccess;
use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Passport\AccessToken;
use Symfony\Component\HttpFoundation\Response;

class UserInfoController extends Controller
{
    public function __invoke(Request $request, AutoGrantApplicationAccess $autoGrant): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, Response::HTTP_UNAUTHORIZED);

        $token = $user->token();
        abort_unless($token instanceof AccessToken, Response::HTTP_FORBIDDEN);

        $application = Application::where('oauth_client_id', $token->oauth_client_id)
            ->where('active', true)
            ->first();

        abort_if($application === null, Response::HTTP_FORBIDDEN, 'Unknown or inactive application.');

        // An admin signing into a newly registered app connects themselves,
        // rather than being bounced until someone runs a grant by hand.
        $autoGrant->handle($user, $application);

        abort_unless($user->canAccess($application), Response::HTTP_FORBIDDEN, 'You do not have access to this application.');

        return response()->json([
            'sub' => (string) $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'applications' => Application::query()
                ->whereIn('id', $user->accessibleApplicationIds())
                ->where('active', true)
                ->orderBy('slug')
                ->pluck('slug'),
        ]);
    }
}
