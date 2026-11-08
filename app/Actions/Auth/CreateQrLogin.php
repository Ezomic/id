<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Exceptions\QrLoginRejected;
use App\Models\QrLogin;
use App\Models\User;
use App\Services\DeviceFingerprint;
use App\Services\QrLoginPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;

class CreateQrLogin
{
    public function __construct(
        private readonly QrLoginPolicy $policy,
        private readonly DeviceFingerprint $fingerprints,
    ) {}

    /**
     * Direction A is asked for by the signed-out device that will be signed in.
     * Direction B is asked for by a signed-in device, so it has to have
     * confirmed itself recently already: the approval comes minutes later.
     *
     * @throws QrLoginRejected
     */
    public function handle(Request $request, string $direction): IssuedQrLogin
    {
        $this->policy->ensureEnabled();

        $session = $request->session();
        $user = $request->user();
        $isA = $direction === QrLogin::DIRECTION_A;

        if (! $isA) {
            $this->policy->ensureRecentlyConfirmed($session->get('auth.password_confirmed_at'));
        }

        $this->retirePrevious($request);

        $token = $this->policy->newToken();
        $shortCode = $this->policy->newShortCode();
        [$match, $decoys] = $this->policy->newNumbers();
        $secret = bin2hex(random_bytes(32));

        $qrLogin = QrLogin::create([
            'token_hash' => $this->policy->hash($token),
            'code_hash' => $this->policy->hash($shortCode),
            'direction' => $direction,
            'status' => QrLogin::PENDING,
            'match_number' => $match,
            'decoy_numbers' => $decoys,
            'binding_hash' => $isA ? $this->policy->bindingHash($secret) : null,
            'requester_user_agent' => $isA ? $request->userAgent() : null,
            'requester_ip' => $isA ? $request->ip() : null,
            'requester_network' => $isA ? $this->fingerprints->networkFor($request->ip()) : null,
            'approver_user_id' => $user instanceof User && ! $isA ? $user->id : null,
            'expires_at' => Date::now()->addSeconds(QrLoginPolicy::TTL_SECONDS),
        ]);

        if ($isA) {
            $this->policy->rememberRequester($session, $qrLogin, $secret);
        } else {
            $this->policy->rememberCreated($session, $qrLogin);
        }

        return new IssuedQrLogin($qrLogin, $token, $this->policy->formatShortCode($shortCode));
    }

    private function retirePrevious(Request $request): void
    {
        $user = $request->user();

        $previous = $user instanceof User
            ? $this->policy->createdBy($request->session(), $user)
            : $this->policy->requesterFor($request->session());

        if ($previous === null) {
            return;
        }

        QrLogin::query()
            ->whereKey($previous->id)
            ->where('status', QrLogin::PENDING)
            ->update(['status' => QrLogin::EXPIRED, 'expires_at' => Date::now()]);
    }
}
