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
        Schema::create('access_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(User::class, 'actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignIdFor(User::class, 'subject_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignIdFor(Application::class)->nullable()->constrained()->nullOnDelete();
            $table->foreignIdFor(Group::class)->nullable()->constrained()->nullOnDelete();
            $table->string('action'); // grant | revoke | group_member_add | group_member_remove | group_app_grant | group_app_revoke
            $table->timestamp('created_at')->nullable();

            $table->index('subject_user_id');
            $table->index('application_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_audits');
    }
};
