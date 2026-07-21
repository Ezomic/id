<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sign_in_events', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            $table->string('method');                 // passkey | email_code | other
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('application')->nullable(); // OAuth client name, if the login was initiated by an app
            $table->string('device_fingerprint')->index(); // hash of the user agent, for new-device detection
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'device_fingerprint']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sign_in_events');
    }
};
