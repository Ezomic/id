<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Access\RevokeUserTokens;
use App\Actions\Auth\NotifyClientsOfEvent;
use App\Actions\Settings\RequestEmailChange;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Models\LogoutNotification;
use App\Models\User;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('settings/Profile', [
            'mustVerifyEmail' => $user instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
            'pendingEmail' => $user instanceof User ? $user->pending_email : null,
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request, RequestEmailChange $requestEmailChange, NotifyClientsOfEvent $notifyClients): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $validated = $request->validated();
        $email = is_string($validated['email'] ?? null) ? $validated['email'] : $user->email;

        $nameChanged = ($validated['name'] ?? $user->name) !== $user->name;

        $user->fill(['name' => $validated['name'] ?? $user->name])->save();

        if ($nameChanged) {
            $notifyClients->handle($user, LogoutNotification::EVENT_USER_UPDATED, null, [
                'name' => $user->name,
                'email' => $user->email,
            ]);
        }

        if ($email === $user->email) {
            Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

            return to_route('profile.edit');
        }

        $requestEmailChange->handle($user, $email);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Check :email to confirm the change. Your current address keeps working until you do.', ['email' => $email]),
        ]);

        return to_route('profile.edit');
    }

    /**
     * Delete the user's profile.
     */
    public function destroy(ProfileDeleteRequest $request, RevokeUserTokens $revokeTokens): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        // The users row goes away but Passport rows are not cascaded, so without
        // this the deleted account's tokens stay valid at every consumer app.
        $revokeTokens->handle($user);

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
