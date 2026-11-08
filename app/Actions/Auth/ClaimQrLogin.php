<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Exceptions\QrLoginRejected;
use App\Models\QrLogin;
use App\Services\DeviceFingerprint;
use App\Services\QrLoginPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;

class ClaimQrLogin
{
    public function __construct(
        private readonly QrLoginPolicy $policy,
        private readonly DeviceFingerprint $fingerprints,
    ) {}

    /**
     * Direction B: the signed-out phone that opened a code ties the request to
     * its own browser. The first device to do so is the only one that can ever
     * be signed in by it.
     *
     * @throws QrLoginRejected
     */
    public function handle(Request $request, QrLogin $qrLogin): void
    {
        $this->policy->ensureEnabled();
        $this->policy->ensureOpen($qrLogin);

        $secret = bin2hex(random_bytes(32));

        $claimed = QrLogin::query()
            ->whereKey($qrLogin->id)
            ->where('direction', QrLogin::DIRECTION_B)
            ->where('status', QrLogin::PENDING)
            ->where('expires_at', '>', Date::now())
            ->whereNull('binding_hash')
            ->update([
                'binding_hash' => $this->policy->bindingHash($secret),
                'requester_user_agent' => $request->userAgent(),
                'requester_ip' => $request->ip(),
                'requester_network' => $this->fingerprints->networkFor($request->ip()),
                'claimed_at' => Date::now(),
            ]);

        if ($claimed !== 1) {
            throw QrLoginRejected::because(QrLoginRejected::ALREADY_CLAIMED);
        }

        $this->policy->rememberRequester($request->session(), $qrLogin, $secret);
    }
}
