<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Access\RevokeUserTokens;
use App\Actions\Auth\NotifyClientsOfLogout;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EstateLogoutController extends Controller
{
    public function __construct(
        private readonly NotifyClientsOfLogout $notifyClients,
        private readonly RevokeUserTokens $revokeTokens,
    ) {}

    /**
     * ID-48 made logout travel outwards from ID. It did not travel inwards:
     * signing out of billr ended the billr session and left ID and the other
     * six untouched, so the next visit signed straight back in and the user
     * reasonably believed they had logged out.
     *
     * Authenticated with the *user's own access token* rather than as the
     * client. That is what scopes it: an app can only end the session of the
     * person whose token it is holding, so one consumer cannot sign out
     * arbitrary users.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User, Response::HTTP_UNAUTHORIZED);

        // Null covers every session this user has, which is what "the whole
        // estate" means; the caller has no session id to offer anyway.
        $notifications = $this->notifyClients->handle($user);

        $this->revokeTokens->handle($user);

        $this->notifyClients->deliverAfterResponse($notifications);

        return response()->json(['status' => 'signed_out']);
    }
}
