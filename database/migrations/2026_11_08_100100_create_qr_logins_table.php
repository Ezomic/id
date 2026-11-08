<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qr_logins', function (Blueprint $table) {
            $table->id();
            // Keyed hashes, never the credentials: a leaked table signs nobody in.
            $table->string('token_hash')->unique();
            $table->string('code_hash')->unique();
            // a: the signed-out device shows the QR. b: a signed-in device does.
            $table->string('direction', 1);
            $table->string('status')->default('pending');
            $table->unsignedTinyInteger('match_number');
            $table->json('decoy_numbers');
            // Hash of the secret held in the requesting browser's session.
            $table->string('binding_hash')->nullable();
            $table->text('requester_user_agent')->nullable();
            $table->string('requester_ip')->nullable();
            $table->string('requester_network')->nullable();
            $table->foreignIdFor(User::class, 'approver_user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('redeemed_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qr_logins');
    }
};
