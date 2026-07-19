<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->string('description')->nullable()->after('slug');
            $table->string('initials', 4)->nullable()->after('description');
            $table->string('accent', 9)->nullable()->after('initials');
            $table->string('launch_url')->nullable()->after('accent');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn(['description', 'initials', 'accent', 'launch_url']);
        });
    }
};
