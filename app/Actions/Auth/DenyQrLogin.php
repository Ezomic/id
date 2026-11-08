<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Exceptions\QrLoginRejected;
use App\Models\QrLogin;
use App\Models\User;
use App\Services\QrLoginPolicy;
use Illuminate\Http\Request;

class DenyQrLogin
{
    public function __construct(private readonly QrLoginPolicy $policy) {}

    /**
     * Refusing needs no recent confirmation: it can only make things safer.
     *
     * @throws QrLoginRejected
     */
    public function handle(Request $request, QrLogin $qrLogin): void
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw QrLoginRejected::because(QrLoginRejected::FORBIDDEN);
        }

        $this->policy->ensureEnabled();
        $this->policy->ensureOpen($qrLogin);

        if ($qrLogin->direction === QrLogin::DIRECTION_B && $qrLogin->approver_user_id !== $user->id) {
            throw QrLoginRejected::because(QrLoginRejected::FORBIDDEN);
        }

        QrLogin::query()
            ->whereKey($qrLogin->id)
            ->where('status', QrLogin::PENDING)
            ->update(['status' => QrLogin::DENIED]);
    }
}
