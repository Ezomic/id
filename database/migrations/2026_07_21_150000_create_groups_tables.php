<?php

use App\Models\Application;
use App\Models\Group;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        Schema::create('group_user', function (Blueprint $table) {
            $table->foreignIdFor(Group::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            $table->primary(['group_id', 'user_id']);
        });

        Schema::create('application_group', function (Blueprint $table) {
            $table->foreignIdFor(Application::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Group::class)->constrained()->cascadeOnDelete();
            $table->primary(['application_id', 'group_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_group');
        Schema::dropIfExists('group_user');
        Schema::dropIfExists('groups');
    }
};
