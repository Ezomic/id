<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\RedeemRecoveryCode;
use App\Concerns\InteractsWithCurrentUser;
use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireRecentAuthentication;
use App\Models\AccessAudit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Inertia\Inertia;
use Inertia\Response;

class ReauthenticateController extends Controller
{
    use InteractsWithCurrentUser;

    public function show(Request $request): Response
    {
        return Inertia::render('auth/Reauthenticate', [
            'hasPasskeys' => $this->currentUser($request)->passkeys()->exists(),
            'windowMinutes' => RequireRecentAuthentication::WINDOW_MINUTES,
        ]);
    }

    /**
     * The recovery-code fallback, for accounts with no passkey enrolled. The
     * passkey path goes through Fortify's own confirmation endpoint, which sets
     * the same timestamp this reads.
     */
    public function confirm(Request $request, RedeemRecoveryCode $redeem): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string']]);

        $user = $this->currentUser($request);

        if (! $redeem->handle($user, $request->string('code')->toString())) {
            return back()->withErrors(['code' => 'That recovery code is not valid.']);
        }

        $this->markConfirmed($request);

        return redirect()->intended(route('dashboard', absolute: false));
    }

    private function markConfirmed(Request $request): void
    {
        $request->session()->put('auth.password_confirmed_at', Date::now()->unix());

        // Recorded so the trail shows the action was re-authenticated rather
        // than merely performed by whoever held the session.
        AccessAudit::log('reauthenticated', ['subject_user_id' => $this->currentUser($request)->id]);
    }
}
