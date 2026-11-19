<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('community_threads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('locations');
            $table->string('title', 140);
            $table->text('body');
            $table->string('category', 32);
            $table->string('status', 20)->default('pending');
            $table->boolean('pinned')->default(false);
            $table->boolean('locked')->default(false);
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'location_id', 'last_activity_at']);
        });

        Schema::create('community_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('thread_id')->constrained('community_threads')->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->string('status', 20)->default('pending')->index();
            $table->timestamps();
            $table->index(['thread_id', 'status', 'id']);
        });

        Schema::create('community_thread_follows', function (Blueprint $table) {
            $table->foreignId('thread_id')->constrained('community_threads')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->primary(['thread_id', 'user_id']);
        });

        Schema::create('community_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reply_id')->constrained('community_replies')->cascadeOnDelete();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->index();
            $table->unique(['user_id', 'reply_id']);
        });

        Schema::create('community_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('location_id')->constrained('locations');
            $table->string('title', 140);
            $table->text('body');
            $table->string('category', 32);
            $table->string('organizer', 150);
            $table->string('venue', 180);
            $table->string('event_url', 1024)->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at')->nullable();
            $table->string('status', 20)->default('pending');
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_note', 300)->nullable();
            $table->timestamps();
            $table->index(['status', 'location_id', 'starts_at']);
            $table->index(['status', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('community_events');
        Schema::dropIfExists('community_notifications');
        Schema::dropIfExists('community_thread_follows');
        Schema::dropIfExists('community_replies');
        Schema::dropIfExists('community_threads');
    }
};
