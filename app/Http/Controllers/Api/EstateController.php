<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Estate\ReadEstate;
use App\Http\Controllers\Controller;
use App\Models\OAuthClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Laravel\Passport\Guards\TokenGuard;
use Symfony\Component\HttpFoundation\Response;

class EstateController extends Controller
{
    /**
     * Machine-to-machine and read only: which apps are registered and who can
     * reach them, for Atlas. The route already demands a client-credentials
     * token carrying estate:read; this also asks the client itself, so a
     * reader whose scope is withdrawn stops at once rather than when its
     * token expires.
     */
    public function __invoke(ReadEstate $readEstate): JsonResponse
    {
        $guard = Auth::guard('api');
        $client = $guard instanceof TokenGuard ? $guard->client() : null;

        abort_unless(
            $client instanceof OAuthClient && $client->hasScope(OAuthClient::ESTATE_READ),
            Response::HTTP_FORBIDDEN,
        );

        return response()->json($readEstate->handle());
    }
}
