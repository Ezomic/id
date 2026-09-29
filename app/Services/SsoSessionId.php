<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

/**
 * An opaque per-session identifier. The framework's own session id would do the
 * job, but it rotates on regenerate and leaking it anywhere is a session-fixation
 * risk, so authorization records are keyed on this instead.
 *
 * The session alone does not hold it long enough. A remembered browser outlives
 * its 120-minute session, and the session remember-me restores it into starts
 * out empty, so the apps signed in to before would drop out of the next
 * sign-out. The browser keeps it in a cookie as well, for as long as it stays
 * remembered, and a session that has none picks it up from there.
 */
final class SsoSessionId
{
    public const COOKIE = 'sso_browser';

    private const KEY = 'sso_session_id';

    public function for(Request $request, User $user): string
    {
        $id = $this->existing($request, $user) ?? Str::random(48);

        $request->session()->put(self::KEY, $id);
        $this->keepInBrowser($request, $user, $id);

        return $id;
    }

    public function existing(Request $request, User $user): ?string
    {
        $id = $request->session()->get(self::KEY);

        if (is_string($id) && $id !== '') {
            return $id;
        }

        return $this->fromBrowser($request, $user);
    }

    public function forget(): void
    {
        Cookie::queue(Cookie::forget(self::COOKIE));
    }

    /**
     * Bound to the user, so a browser that changes hands starts a new one
     * rather than filing the next person's apps under the previous person's.
     */
    private function keepInBrowser(Request $request, User $user, string $id): void
    {
        $value = $user->id.'|'.$id;

        if ($request->cookie(self::COOKIE) !== $value) {
            Cookie::queue(self::COOKIE, $value, Config::integer('auth.guards.web.remember', 576000));
        }
    }

    private function fromBrowser(Request $request, User $user): ?string
    {
        $value = $request->cookie(self::COOKIE);

        if (! is_string($value)) {
            return null;
        }

        [$userId, $id] = array_pad(explode('|', $value, 2), 2, '');

        return $userId === (string) $user->id && $id !== '' ? $id : null;
    }
}
