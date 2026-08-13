<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class InvitationController extends Controller
{
    /**
     * An invitation link signs the invitee in directly. It is a single-use
     * secret delivered to the address it authenticates, which is the same
     * property the email login code relies on, so this adds no new trust.
     *
     * The point is that the first experience is not "guess how this works".
     */
    public function accept(Request $request, string $token): RedirectResponse
    {
        $user = $this->matching($token);

        if ($user === null) {
            return redirect()->route('login')->withErrors([
                'email' => 'That invitation link is invalid or has expired. Sign in with a code instead.',
            ]);
        }

        $user->forceFill([
            'invitation_token' => null,
            'invitation_expires_at' => null,
            'invitation_accepted_at' => CarbonImmutable::now(),
            'email_verified_at' => $user->email_verified_at ?? CarbonImmutable::now(),
        ])->save();

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }

    /**
     * Tokens are hashed, so the candidate set is every unexpired invitation.
     * There is at most a handful at any time and the alternative is storing
     * something reversible.
     */
    private function matching(string $token): ?User
    {
        return User::query()
            ->whereNotNull('invitation_token')
            ->where('invitation_expires_at', '>', CarbonImmutable::now())
            ->get()
            ->first(fn (User $user): bool => Hash::check($token, (string) $user->invitation_token));
    }
}
