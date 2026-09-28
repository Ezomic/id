<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\Access\ForgetRememberedBrowsers;
use App\Concerns\InteractsWithCurrentUser;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class SessionController extends Controller
{
    use InteractsWithCurrentUser;

    public function index(Request $request): Response
    {
        $currentId = $request->session()->getId();

        $sessions = DB::table('sessions')
            ->where('user_id', $this->currentUser($request)->id)
            ->orderByDesc('last_activity')
            ->get()
            ->map(fn (object $session): array => [
                'id' => $session->id,
                'ip_address' => $session->ip_address,
                'user_agent' => $session->user_agent,
                'last_active_diff' => CarbonImmutable::createFromTimestamp(is_numeric($session->last_activity) ? (int) $session->last_activity : 0)->diffForHumans(),
                'is_current' => $session->id === $currentId,
            ])
            ->values()
            ->all();

        return Inertia::render('settings/Sessions', ['sessions' => $sessions]);
    }

    public function destroy(Request $request, string $id, ForgetRememberedBrowsers $forgetBrowsers): RedirectResponse
    {
        // The current session must never be killed silently — the user would
        // be signed out of the very page they are acting from with no warning.
        if ($id === $request->session()->getId()) {
            return back()->withErrors([
                'session' => 'You cannot revoke the session you are currently using. Sign out instead.',
            ]);
        }

        $user = $this->currentUser($request);

        DB::table('sessions')
            ->where('user_id', $user->id)
            ->where('id', $id)
            ->delete();

        // The revoked browser's cookie cannot be ended on its own, so every
        // other remembered browser loses its cookie too and keeps only its live
        // session. Leaving the cookie would let the revoked browser straight
        // back in, which makes the button do nothing.
        $forgetBrowsers->handle($user, except: $request);

        return back()->with('status', 'Session revoked.');
    }

    public function destroyOthers(Request $request, ForgetRememberedBrowsers $forgetBrowsers): RedirectResponse
    {
        $user = $this->currentUser($request);

        DB::table('sessions')
            ->where('user_id', $user->id)
            ->where('id', '!=', $request->session()->getId())
            ->delete();

        $forgetBrowsers->handle($user, except: $request);

        return back()->with('status', 'All other sessions were signed out.');
    }
}
