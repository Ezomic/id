<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\CreateQrLogin;
use App\Actions\Auth\RedeemQrLogin;
use App\Exceptions\QrLoginRejected;
use App\Http\Controllers\Controller;
use App\Models\QrLogin;
use App\Models\User;
use App\Services\DeviceFingerprint;
use App\Services\QrCodeRenderer;
use App\Services\QrLoginPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;

class QrLoginController extends Controller
{
    /**
     * Direction A. Called by script rather than on page load, so a bare GET of
     * the login page never writes a row.
     */
    public function create(Request $request, CreateQrLogin $createQrLogin, QrCodeRenderer $renderer): JsonResponse
    {
        try {
            $issued = $createQrLogin->handle($request, QrLogin::DIRECTION_A);
        } catch (QrLoginRejected $rejected) {
            return $this->rejected($rejected);
        }

        return response()->json([
            'qr' => $renderer->dataUri(route('qr.show', ['credential' => $issued->token])),
            'number' => $issued->qrLogin->match_number,
            'short_code' => $issued->shortCode,
            'expires_in' => QrLoginPolicy::TTL_SECONDS,
        ]);
    }

    /**
     * The browser's own view of the request it started, found through its
     * session. Nothing in the URL identifies a request, so there is nothing
     * for a bystander to poll.
     */
    public function status(Request $request, QrLoginPolicy $policy, DeviceFingerprint $fingerprints): JsonResponse
    {
        $user = $request->user();

        $qrLogin = $user instanceof User
            ? $policy->createdBy($request->session(), $user)
            : $policy->requesterFor($request->session());

        if ($qrLogin === null) {
            return response()->json(['status' => 'none']);
        }

        $status = $qrLogin->effectiveStatus();
        $payload = [
            'status' => $status,
            'claimed' => $qrLogin->isClaimed(),
            'expires_in' => max(0, $qrLogin->expires_at->getTimestamp() - Date::now()->getTimestamp()),
        ];

        if ($user instanceof User && $qrLogin->isClaimed() && $status === QrLogin::PENDING) {
            $payload['request'] = $qrLogin->requesterDevice($fingerprints);
            $payload['options'] = $qrLogin->numberOptions();
        }

        return response()->json($payload);
    }

    public function redeem(Request $request, RedeemQrLogin $redeemQrLogin): JsonResponse
    {
        try {
            $redeemQrLogin->handle($request);
        } catch (QrLoginRejected $rejected) {
            return $this->rejected($rejected);
        }

        // The page follows this with a full visit: when the sign-in began at an
        // app the intended URL is /oauth/authorize, which a script cannot follow
        // across origins.
        return response()->json([
            'redirect' => redirect()->intended(route('dashboard', absolute: false))->getTargetUrl(),
        ]);
    }

    /**
     * A typed short code. The same lookup runs again when the page it leads to
     * is opened, and both sit behind the strict limiter.
     */
    public function code(Request $request, QrLoginPolicy $policy): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string', 'max:32']]);

        $code = $policy->normaliseShortCode($request->string('code')->toString());

        if (strlen($code) !== 8 || $policy->find($code) === null) {
            return back()->withErrors(['code' => 'That code is not valid or has expired.']);
        }

        return redirect()->route('qr.show', ['credential' => $policy->formatShortCode($code)]);
    }

    private function rejected(QrLoginRejected $rejected): JsonResponse
    {
        return response()->json(
            ['reason' => $rejected->reason, 'message' => $rejected->getMessage()],
            $rejected->httpStatus(),
        );
    }
}
