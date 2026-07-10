<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Passport\AccessToken;
use Symfony\Component\HttpFoundation\Response;

class UserInfoController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, Response::HTTP_UNAUTHORIZED);

        $token = $user->token();
        abort_unless($token instanceof AccessToken, Response::HTTP_FORBIDDEN);

        $application = Application::where('oauth_client_id', $token->oauth_client_id)
            ->where('active', true)
            ->first();

        abort_if($application === null, Response::HTTP_FORBIDDEN, 'Unknown or inactive application.');
        abort_unless($user->canAccess($application), Response::HTTP_FORBIDDEN, 'You do not have access to this application.');

        return response()->json([
            'sub' => (string) $user->getKey(),
            'name' => $user->name,
            'email' => $user->email,
            'applications' => $user->applications()
                ->where('active', true)
                ->orderBy('slug')
                ->pluck('slug'),
        ]);
    }
}
