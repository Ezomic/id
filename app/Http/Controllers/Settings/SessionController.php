<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class SessionController extends Controller
{
    public function index(Request $request): Response
    {
        $currentId = $request->session()->getId();

        $sessions = DB::table('sessions')
            ->where('user_id', $request->user()->id)
            ->orderByDesc('last_activity')
            ->get()
            ->map(fn (object $session): array => [
                'id' => $session->id,
                'ip_address' => $session->ip_address,
                'user_agent' => $session->user_agent,
                'last_active_diff' => CarbonImmutable::createFromTimestamp($session->last_activity)->diffForHumans(),
                'is_current' => $session->id === $currentId,
            ])
            ->values()
            ->all();

        return Inertia::render('settings/Sessions', ['sessions' => $sessions]);
    }

    public function destroy(Request $request, string $id): RedirectResponse
    {
        // The current session must never be killed silently — the user would
        // be signed out of the very page they are acting from with no warning.
        if ($id === $request->session()->getId()) {
            return back()->withErrors([
                'session' => 'You cannot revoke the session you are currently using. Sign out instead.',
            ]);
        }

        DB::table('sessions')
            ->where('user_id', $request->user()->id)
            ->where('id', $id)
            ->delete();

        return back()->with('status', 'Session revoked.');
    }

    public function destroyOthers(Request $request): RedirectResponse
    {
        DB::table('sessions')
            ->where('user_id', $request->user()->id)
            ->where('id', '!=', $request->session()->getId())
            ->delete();

        return back()->with('status', 'All other sessions were signed out.');
    }
}
