<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Every workflow app calls ID machine-to-machine to populate its portal
     * switcher, which needs client_credentials. Clients created through
     * `id:app` never had it, so the switcher rendered "No other apps available"
     * for them, silently, because IdPortalClient fails soft.
     *
     * The working consumers evidently had the grant added by hand at some point.
     * This backfills the ones that were missed rather than leaving two apps
     * quietly broken and the two paths drifting apart again.
     */
    public function up(): void
    {
        foreach (DB::table('oauth_clients')->where('revoked', false)->get() as $client) {
            $raw = $client->grant_types;
            $grants = is_string($raw) ? json_decode($raw, true) : null;

            if (! is_array($grants)) {
                continue;
            }

            // Only auth-code clients: leave anything purpose-built alone.
            if (! in_array('authorization_code', $grants, true)) {
                continue;
            }

            if (in_array('client_credentials', $grants, true)) {
                continue;
            }

            DB::table('oauth_clients')
                ->where('id', $client->id)
                ->update(['grant_types' => json_encode([...$grants, 'client_credentials'])]);
        }
    }

    /**
     * Deliberately not reversible. Removing the grant again would re-break the
     * portal switcher, and there is no record of which clients lacked it.
     */
    public function down(): void {}
};
