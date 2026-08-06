<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Access\ConnectedApplications;
use App\Concerns\InteractsWithCurrentUser;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Features;

class SecurityController extends Controller
{
    use InteractsWithCurrentUser;

    /**
     * Show the user's security settings page.
     */
    public function edit(Request $request, ConnectedApplications $connectedApplications): Response
    {
        return Inertia::render('settings/Security', [
            'connections' => $connectedApplications->handle($this->currentUser($request)),
            'newRecoveryCodes' => $request->session()->get('recovery_codes'),
            'unusedRecoveryCodes' => $this->currentUser($request)->recoveryCodes()->whereNull('used_at')->count(),
            'canManagePasskeys' => Features::canManagePasskeys(),
            'passkeys' => Features::canManagePasskeys()
                ? $this->currentUser($request)
                    ->passkeys()
                    ->select(['id', 'name', 'credential', 'created_at', 'last_used_at'])
                    ->latest()
                    ->get()
                    ->map(fn ($passkey) => [
                        'id' => $passkey->id,
                        'name' => $passkey->name,
                        'authenticator' => $passkey->authenticator,
                        'created_at_diff' => $passkey->created_at?->diffForHumans(),
                        'last_used_at_diff' => $passkey->last_used_at?->diffForHumans(),
                    ])
                    ->values()
                    ->all()
                : [],
        ]);
    }
}
