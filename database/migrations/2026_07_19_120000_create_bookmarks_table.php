<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookmarks', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            $table->string('url', 2048);
            $table->string('title')->nullable();
            $table->string('domain')->nullable();
            $table->string('image', 2048)->nullable();
            $table->text('note')->nullable();
            $table->json('tags')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'archived_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookmarks');
    }
};
