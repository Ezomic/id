<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Exceptions\QrLoginRejected;
use App\Models\QrLogin;
use App\Models\User;
use App\Services\QrLoginPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;

class RedeemQrLogin
{
    public function __construct(private readonly QrLoginPolicy $policy) {}

    /**
     * Signs the browser that started the request in as whoever approved it.
     * The session secret is what proves it is that browser; the atomic status
     * change is what makes the sign-in happen once.
     *
     * @throws QrLoginRejected
     */
    public function handle(Request $request): User
    {
        $this->policy->ensureEnabled();

        $session = $request->session();
        $qrLogin = $this->policy->requesterFor($session)
            ?? throw QrLoginRejected::because(QrLoginRejected::NOT_BOUND);

        $redeemed = QrLogin::query()
            ->whereKey($qrLogin->id)
            ->where('status', QrLogin::APPROVED)
            ->where('expires_at', '>', Date::now())
            ->whereNotNull('approver_user_id')
            ->update(['status' => QrLogin::REDEEMED, 'redeemed_at' => Date::now()]);

        if ($redeemed !== 1) {
            throw $this->whyNot($qrLogin->refresh());
        }

        $user = $qrLogin->refresh()->approver ?? throw QrLoginRejected::because(QrLoginRejected::USED);

        $this->policy->forgetAll($session);

        // The same remembered session every other way in produces, and no
        // TrustedDevice row: signing in this way does not vouch for the device.
        Auth::login($user, remember: true);
        $session->regenerate();

        return $user;
    }

    private function whyNot(QrLogin $qrLogin): QrLoginRejected
    {
        return match ($qrLogin->effectiveStatus()) {
            QrLogin::PENDING => QrLoginRejected::because(QrLoginRejected::NOT_APPROVED),
            QrLogin::EXPIRED => QrLoginRejected::because(QrLoginRejected::EXPIRED),
            default => QrLoginRejected::because(QrLoginRejected::USED),
        };
    }
}
