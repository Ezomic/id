<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Concerns\InteractsWithCurrentUser;
use App\Http\Controllers\Controller;
use App\Models\TrustedDevice;
use App\Services\DeviceFingerprint;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TrustedDeviceController extends Controller
{
    use InteractsWithCurrentUser;

    /**
     * Trusts the device making this request. Deliberately not "trust a device by
     * id": you can only vouch for the thing you are holding.
     */
    public function store(Request $request, DeviceFingerprint $fingerprints): RedirectResponse
    {
        $userAgent = $request->userAgent();
        $fingerprint = $fingerprints->forUserAgent($userAgent);

        TrustedDevice::updateOrCreate(
            [
                'user_id' => $this->currentUser($request)->id,
                'device_fingerprint' => $fingerprint,
            ],
            [
                'label' => $fingerprints->label($userAgent),
                'expires_at' => CarbonImmutable::now()->addDays(TrustedDevice::TRUST_DAYS),
            ],
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('This device is trusted for :days days.', ['days' => TrustedDevice::TRUST_DAYS])]);

        return back();
    }

    /**
     * Revoking trust does not sign the device out. It only means the account
     * starts being told about sign-ins from it again.
     */
    public function destroy(Request $request, TrustedDevice $trustedDevice): RedirectResponse
    {
        abort_unless($trustedDevice->user_id === $this->currentUser($request)->id, 403);

        $trustedDevice->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Device trust revoked.')]);

        return back();
    }
}
