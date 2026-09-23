<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * `id:app` never wrote launch_url and PortalController refuses to launch an
 * application without one, so every app the command registered was inert on
 * the dashboard. CreateApplication now derives it from the callback; this
 * backfills the rows registered before it did. See ID-80.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = DB::table('applications')
            ->join('oauth_clients', 'oauth_clients.id', '=', 'applications.oauth_client_id')
            ->where(fn (Builder $query) => $query->whereNull('applications.launch_url')->orWhere('applications.launch_url', ''))
            ->select('applications.id as application_id', 'oauth_clients.redirect_uris')
            ->get();

        foreach ($rows as $row) {
            $origin = $this->origin($this->firstRedirectUri($row->redirect_uris ?? null));

            if ($origin === null) {
                continue;
            }

            DB::table('applications')
                ->where('id', $row->application_id)
                ->update(['launch_url' => $origin]);
        }
    }

    /**
     * Backfilled values are indistinguishable from hand-set ones, so reversing
     * this would discard real data rather than restore a previous state.
     */
    public function down(): void {}

    private function firstRedirectUri(mixed $value): ?string
    {
        $uris = is_string($value) ? json_decode($value, true) : $value;

        if (! is_array($uris)) {
            return null;
        }

        $first = reset($uris);

        return is_string($first) && $first !== '' ? $first : null;
    }

    private function origin(?string $uri): ?string
    {
        if ($uri === null) {
            return null;
        }

        $parts = parse_url($uri);

        if ($parts === false) {
            return null;
        }

        $scheme = $parts['scheme'] ?? null;
        $host = $parts['host'] ?? null;

        if (! is_string($scheme) || ! is_string($host)) {
            return null;
        }

        $port = $parts['port'] ?? null;

        return $scheme.'://'.$host.(is_int($port) ? ':'.$port : '');
    }
};
