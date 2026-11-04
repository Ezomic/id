<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Pitch has its own sign-in and only uses ID for the portal switcher, so
     * it keeps client_credentials and loses the SSO callback and logout secret.
     * The client id and secret stay, so its .env keeps working.
     */
    public function up(): void
    {
        $application = DB::table('applications')->where('slug', 'pitch')->first();

        if ($application === null || $application->oauth_client_id === null) {
            return;
        }

        $client = DB::table('oauth_clients')->where('id', $application->oauth_client_id)->first();

        if ($client === null) {
            return;
        }

        if ($application->launch_url === null || $application->launch_url === '') {
            $application->launch_url = $this->origin($client->redirect_uris);
        }

        DB::table('applications')->where('id', $application->id)->update([
            'launch_url' => $application->launch_url,
            'logout_secret' => null,
            'typed_events_confirmed_at' => null,
        ]);

        DB::table('oauth_clients')->where('id', $client->id)->update([
            'redirect_uris' => json_encode([]),
            'grant_types' => json_encode(['client_credentials']),
        ]);
    }

    /**
     * Not reversible: the callback URI and logout secret pointed at endpoints
     * pitch never had, and restoring them would bring the failing check back.
     */
    public function down(): void {}

    private function origin(mixed $redirectUris): ?string
    {
        $uris = is_string($redirectUris) ? json_decode($redirectUris, true) : null;
        $first = is_array($uris) ? reset($uris) : null;
        $parts = is_string($first) ? parse_url($first) : false;

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
};
