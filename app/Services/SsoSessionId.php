<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * An opaque per-session identifier. The framework's own session id would do the
 * job, but it rotates on regenerate and leaking it anywhere is a session-fixation
 * risk, so authorization records are keyed on this instead.
 */
final class SsoSessionId
{
    private const KEY = 'sso_session_id';

    public function for(Request $request): string
    {
        $existing = $request->session()->get(self::KEY);

        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $id = Str::random(48);
        $request->session()->put(self::KEY, $id);

        return $id;
    }

    public function existing(Request $request): ?string
    {
        $id = $request->session()->get(self::KEY);

        return is_string($id) && $id !== '' ? $id : null;
    }
}
