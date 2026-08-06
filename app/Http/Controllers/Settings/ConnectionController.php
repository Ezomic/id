<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\Access\RevokeUserTokens;
use App\Concerns\InteractsWithCurrentUser;
use App\Http\Controllers\Controller;
use App\Models\AccessAudit;
use App\Models\Application;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ConnectionController extends Controller
{
    use InteractsWithCurrentUser;

    /**
     * Disconnecting revokes the tokens but leaves the access grant alone: the
     * user is saying "sign me out of that app", not "take away my access".
     */
    public function destroy(Request $request, Application $application, RevokeUserTokens $revokeTokens): RedirectResponse
    {
        $user = $this->currentUser($request);

        $revokeTokens->handle($user, [$application->id]);

        AccessAudit::log('disconnect', [
            'subject_user_id' => $user->id,
            'application_id' => $application->id,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Disconnected from :app.', ['app' => $application->name])]);

        return to_route('security.edit');
    }
}
