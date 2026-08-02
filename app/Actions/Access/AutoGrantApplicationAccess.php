<?php

declare(strict_types=1);

namespace App\Actions\Access;

use App\Models\AccessAudit;
use App\Models\Application;
use App\Models\User;

final class AutoGrantApplicationAccess
{
    /**
     * Registering an app with `id:app` creates the Application and its OAuth
     * client but attaches no users, so the first SSO login into a brand new app
     * fails with "You do not have access to this application." That is a manual
     * step with no security value for the person who registered the app.
     *
     * Admins therefore connect themselves on first sign-in. Everyone else still
     * needs an explicit grant, from the admin UI or `id:admin --all-apps`.
     *
     * Scoped to admins deliberately. Auto-granting every user access to every
     * registered app would mean a second, non-admin account silently gaining
     * access to everything the moment a new app appears, which is a far larger
     * change than the friction it removes.
     *
     * Returns true when access was granted here.
     */
    public function handle(User $user, Application $application): bool
    {
        if (! $user->is_admin || ! $application->active) {
            return false;
        }

        if ($user->canAccess($application)) {
            return false;
        }

        $user->applications()->syncWithoutDetaching([$application->id]);

        // A self-service grant is exactly the kind of access change worth having
        // a record of, so it is audited like any admin-issued one.
        AccessAudit::log('auto_grant', [
            'subject_user_id' => $user->id,
            'application_id' => $application->id,
        ]);

        return true;
    }
}
