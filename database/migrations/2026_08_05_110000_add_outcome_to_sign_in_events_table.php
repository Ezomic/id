<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sign_in_events', function (Blueprint $table) {
            $table->string('outcome')->default('success')->after('method')->index();
        });

        // An attempt against an address with no account has no user to hang off,
        // and inventing one to record it would be worse than not recording it.
        Schema::table('sign_in_events', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('sign_in_events', function (Blueprint $table) {
            $table->dropColumn('outcome');
        });
    }
};
