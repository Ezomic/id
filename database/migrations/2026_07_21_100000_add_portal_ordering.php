<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('application_user', function (Blueprint $table) {
            $table->boolean('pinned')->default(false);
            $table->unsignedInteger('position')->nullable();
            $table->timestamp('last_launched_at')->nullable();
        });

        Schema::table('bookmarks', function (Blueprint $table) {
            $table->boolean('pinned')->default(false);
            $table->unsignedInteger('position')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('application_user', function (Blueprint $table) {
            $table->dropColumn(['pinned', 'position', 'last_launched_at']);
        });

        Schema::table('bookmarks', function (Blueprint $table) {
            $table->dropColumn(['pinned', 'position']);
        });
    }
};
