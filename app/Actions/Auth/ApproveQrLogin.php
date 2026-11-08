<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Exceptions\QrLoginRejected;
use App\Models\QrLogin;
use App\Models\User;
use App\Services\QrLoginPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;

class ApproveQrLogin
{
    public function __construct(private readonly QrLoginPolicy $policy) {}

    /**
     * @throws QrLoginRejected
     */
    public function handle(Request $request, QrLogin $qrLogin, int $chosenNumber): void
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw QrLoginRejected::because(QrLoginRejected::FORBIDDEN);
        }

        $this->policy->ensureEnabled();
        $this->policy->ensureRecentlyConfirmed($request->session()->get('auth.password_confirmed_at'));
        $this->policy->ensureOpen($qrLogin);
        $this->policy->ensureMayAnswer($qrLogin, $user);

        try {
            $this->policy->ensureNumberMatches($qrLogin, $chosenNumber);
        } catch (QrLoginRejected $rejected) {
            // One wrong pick ends the request: three options must not become a
            // guessing game for whoever is relaying this one.
            QrLogin::query()
                ->whereKey($qrLogin->id)
                ->where('status', QrLogin::PENDING)
                ->update(['status' => QrLogin::DENIED]);

            throw $rejected;
        }

        $approved = QrLogin::query()
            ->whereKey($qrLogin->id)
            ->where('status', QrLogin::PENDING)
            ->where('expires_at', '>', Date::now())
            ->where(fn ($query) => $query->whereNull('approver_user_id')->orWhere('approver_user_id', $user->id))
            ->update([
                'status' => QrLogin::APPROVED,
                'approver_user_id' => $user->id,
                'approved_at' => Date::now(),
            ]);

        if ($approved !== 1) {
            throw QrLoginRejected::because(QrLoginRejected::USED);
        }
    }
}
