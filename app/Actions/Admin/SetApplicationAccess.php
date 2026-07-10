<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Models\User;

class SetApplicationAccess
{
    /**
     * @param  array<int, int>  $applicationIds
     */
    public function handle(User $user, array $applicationIds): void
    {
        $user->applications()->sync($applicationIds);
    }
}
