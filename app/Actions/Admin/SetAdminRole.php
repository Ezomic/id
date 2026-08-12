<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Models\AccessAudit;
use App\Models\User;
use RuntimeException;

final class SetAdminRole
{
    /**
     * Promoting is how the estate stops depending on one account. Demoting is
     * how it accidentally starts again, so the last one is refused: with no
     * admins nothing can grant access, register a client or rotate a secret,
     * and the only way back is SSH and `id:admin`.
     *
     * @throws RuntimeException when this would leave no administrator
     */
    public function handle(User $user, bool $isAdmin): void
    {
        if (! $isAdmin && $user->is_admin && User::where('is_admin', true)->count() <= 1) {
            throw new RuntimeException('This is the only administrator. Promote someone else first.');
        }

        if ($user->is_admin === $isAdmin) {
            return;
        }

        $user->forceFill(['is_admin' => $isAdmin])->save();

        AccessAudit::log($isAdmin ? 'admin_promote' : 'admin_demote', ['subject_user_id' => $user->id]);
    }
}
