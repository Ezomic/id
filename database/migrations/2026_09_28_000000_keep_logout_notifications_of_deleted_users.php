<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Deleting an account is when every app most needs telling, and the cascade
 * took the pending notifications with the user row, before the after-response
 * delivery or the scheduled retry could send them. Delivery only reads the id,
 * as the sub, so it stays as a plain column. The users id is AUTOINCREMENT, so
 * a retried call can never name somebody else. See ID-90.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('logout_notifications', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });
    }

    public function down(): void
    {
        // Rows still owed for a deleted account cannot satisfy the key again.
        DB::table('logout_notifications')
            ->whereNotIn('user_id', DB::table('users')->select('id'))
            ->delete();

        Schema::table('logout_notifications', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }
};
