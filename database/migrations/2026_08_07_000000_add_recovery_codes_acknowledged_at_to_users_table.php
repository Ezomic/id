<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('recovery_codes_acknowledged_at')->nullable()->after('pending_email_expires_at');
        });

        // Anyone who already holds codes got them under the old flow, where the
        // security page displayed whatever was in the session and nothing was
        // recorded. Treating those as unacknowledged is the safe reading: the
        // worst case is one prompt for someone who did save them, against a
        // silent lockout for someone who did not.
        DB::table('users')->update(['recovery_codes_acknowledged_at' => null]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('recovery_codes_acknowledged_at');
        });
    }
};
