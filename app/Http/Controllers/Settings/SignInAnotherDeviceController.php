<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\Auth\CreateQrLogin;
use App\Exceptions\QrLoginRejected;
use App\Http\Controllers\Controller;
use App\Models\QrLogin;
use App\Services\QrCodeRenderer;
use App\Services\QrLoginPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SignInAnotherDeviceController extends Controller
{
    public function edit(Request $request, QrLoginPolicy $policy): Response
    {
        $confirmed = $policy->recentlyConfirmed($request->session()->get('auth.password_confirmed_at'));

        if (! $confirmed) {
            $request->session()->put('url.intended', route('sign-in-another-device.edit'));
        }

        return Inertia::render('settings/SignInAnotherDevice', [
            'confirmed' => $confirmed,
            'windowMinutes' => QrLoginPolicy::REAUTH_WINDOW_MINUTES,
        ]);
    }

    /**
     * Direction B: this signed-in device shows the code.
     */
    public function store(Request $request, CreateQrLogin $createQrLogin, QrCodeRenderer $renderer): JsonResponse
    {
        try {
            $issued = $createQrLogin->handle($request, QrLogin::DIRECTION_B);
        } catch (QrLoginRejected $rejected) {
            return response()->json(
                ['reason' => $rejected->reason, 'message' => $rejected->getMessage()],
                $rejected->httpStatus(),
            );
        }

        return response()->json([
            'qr' => $renderer->dataUri(route('qr.show', ['credential' => $issued->token])),
            'token' => $issued->token,
            'short_code' => $issued->shortCode,
            'expires_in' => QrLoginPolicy::TTL_SECONDS,
        ]);
    }
}
