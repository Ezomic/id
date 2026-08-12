<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Access\ConnectedApplications;
use App\Concerns\InteractsWithCurrentUser;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Features;

class SecurityController extends Controller
{
    use InteractsWithCurrentUser;

    /**
     * Snoozes the enrollment nudge rather than silencing it: an account with no
     * passkey is still an account whose only way in is an emailed code.
     */
    public function dismissPasskeyPrompt(Request $request): RedirectResponse
    {
        $this->currentUser($request)
            ->forceFill(['passkey_prompt_dismissed_at' => CarbonImmutable::now()])
            ->save();

        return back();
    }

    /**
     * Show the user's security settings page.
     */
    public function edit(Request $request, ConnectedApplications $connectedApplications): Response
    {
        return Inertia::render('settings/Security', [
            'connections' => $connectedApplications->handle($this->currentUser($request)),
            'newRecoveryCodes' => $request->session()->get('recovery_codes'),
            'recoveryCodesUnsaved' => $this->currentUser($request)->hasUnacknowledgedRecoveryCodes(),
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
