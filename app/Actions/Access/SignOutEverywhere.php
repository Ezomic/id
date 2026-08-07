<?php

declare(strict_types=1);

namespace App\Actions\Access;

use App\Actions\Auth\NotifyClientsOfLogout;
use App\Models\AccessAudit;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class SignOutEverywhere
{
    public function __construct(
        private readonly RevokeUserTokens $revokeUserTokens,
        private readonly NotifyClientsOfLogout $notifyClients,
    ) {}

    /**
     * Offboarding or a suspected compromise used to mean working directly on the
     * production database. Kills the browser sessions at ID, every OAuth
     * credential the consumer apps hold, and the consumers' own sessions.
     *
     * The Logout event is deliberately not used here. It carries no user scope,
     * so PropagateLogout reads the session id off the current request, and the
     * current request belongs to the admin rather than to the person being
     * signed out. Every session of the target user is covered instead.
     */
    public function handle(User $user): void
    {
        DB::table('sessions')->where('user_id', $user->id)->delete();

        $notifications = $this->notifyClients->handle($user);

        // Belt and braces: NotifyClientsOfLogout only revokes for clients the
        // user actually authorized, and a token can outlive its authorization
        // record if that record was pruned.
        $this->revokeUserTokens->handle($user);

        AccessAudit::log('force_sign_out', ['subject_user_id' => $user->id]);

        $this->notifyClients->deliverAfterResponse($notifications);
    }
}
