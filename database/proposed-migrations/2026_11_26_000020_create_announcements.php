<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('confirmation_hash', 64)->unique();
            $table->string('title', 120);
            $table->text('body');
            $table->string('audience', 20);
            $table->unsignedInteger('recipient_count');
            $table->timestamp('created_at');
        });
        Schema::create('announcement_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('announcement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at');
            $table->unique(['announcement_id', 'user_id']);
            $table->index(['user_id', 'read_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcement_receipts');
        Schema::dropIfExists('announcements');
    }
};
