<?php

use App\Models\Application;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->string('logout_secret')->nullable()->after('oauth_client_id');
        });

        // The OAuth client secret is hashed at rest, so it cannot be used to
        // sign anything. Back-channel logout gets its own shared secret.
        Application::query()->whereNull('logout_secret')->get()->each(
            fn (Application $application) => DB::table('applications')
                ->where('id', $application->id)
                ->update(['logout_secret' => Str::random(64)]),
        );

        Schema::create('authorized_clients', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            $table->string('sso_session_id')->index();
            $table->foreignUuid('oauth_client_id');
            $table->timestamps();

            $table->unique(['sso_session_id', 'oauth_client_id']);
        });

        Schema::create('logout_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Application::class)->constrained()->cascadeOnDelete();
            $table->string('endpoint');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('delivered_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['delivered_at', 'attempts']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('logout_notifications');
        Schema::dropIfExists('authorized_clients');

        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('logout_secret');
        });
    }
};
