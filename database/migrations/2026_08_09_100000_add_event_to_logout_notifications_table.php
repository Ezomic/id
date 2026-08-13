<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('logout_notifications', function (Blueprint $table) {
            // Existing rows are all logouts, which is exactly what the default
            // says, so in-flight deliveries survive the migration rather than
            // being dropped and re-owed.
            $table->string('event')->default('logout')->after('application_id')->index();
            $table->json('payload')->nullable()->after('event');
        });
    }

    public function down(): void
    {
        Schema::table('logout_notifications', function (Blueprint $table) {
            $table->dropColumn(['event', 'payload']);
        });
    }
};
