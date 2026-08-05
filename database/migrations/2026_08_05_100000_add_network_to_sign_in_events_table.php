<?php

use App\Services\DeviceFingerprint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sign_in_events', function (Blueprint $table) {
            $table->string('network')->nullable()->after('ip_address');
        });

        // Existing fingerprints hash the raw user agent. Leaving them would make
        // every user's next sign-in look like a new device, so recompute them
        // from the stored user agent under the new scheme.
        $fingerprints = new DeviceFingerprint;

        DB::table('sign_in_events')
            ->select(['id', 'ip_address', 'user_agent'])
            ->orderBy('id')
            ->chunk(500, function ($events) use ($fingerprints) {
                foreach ($events as $event) {
                    $userAgent = is_string($event->user_agent) ? $event->user_agent : null;
                    $ip = is_string($event->ip_address) ? $event->ip_address : null;

                    DB::table('sign_in_events')->where('id', $event->id)->update([
                        'device_fingerprint' => $fingerprints->forUserAgent($userAgent),
                        'network' => $fingerprints->networkFor($ip),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('sign_in_events', function (Blueprint $table) {
            $table->dropColumn('network');
        });
    }
};
