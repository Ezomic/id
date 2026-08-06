<?php

declare(strict_types=1);

namespace App\Actions\Access;

use App\Models\AccessAudit;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class SignOutEverywhere
{
    public function __construct(private readonly RevokeUserTokens $revokeUserTokens) {}

    /**
     * Offboarding or a suspected compromise used to mean working directly on the
     * production database. Kills the browser sessions at ID and every OAuth
     * credential the consumer apps hold.
     */
    public function handle(User $user): void
    {
        DB::table('sessions')->where('user_id', $user->id)->delete();

        $this->revokeUserTokens->handle($user);

        AccessAudit::log('force_sign_out', ['subject_user_id' => $user->id]);
    }
}
