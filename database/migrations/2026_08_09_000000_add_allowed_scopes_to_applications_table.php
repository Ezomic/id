<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            // Null means "registered before scopes existed": the application
            // keeps today's all-or-nothing behaviour until someone opts it in.
            // Backfilling every app to a scope set here would break seven live
            // consumers the moment this deploys, which is not a trade worth
            // making to tighten a boundary.
            $table->json('allowed_scopes')->nullable()->after('logout_secret');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('allowed_scopes');
        });
    }
};
