<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            // Null means "not yet probed", which is treated as "assume the
            // oldest behaviour". Every application predates the probe, so the
            // conservative default is the correct one for all of them.
            $table->timestamp('typed_events_confirmed_at')->nullable()->after('logout_secret');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('typed_events_confirmed_at');
        });
    }
};
