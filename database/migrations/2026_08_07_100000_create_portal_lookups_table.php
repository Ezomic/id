<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_lookups', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('oauth_client_id')->index();
            // Which addresses a client asked about is the question worth
            // answering after a leaked secret, so the subject is recorded even
            // when it matches no account.
            $table->string('subject_email');
            $table->boolean('matched');
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['oauth_client_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_lookups');
    }
};
