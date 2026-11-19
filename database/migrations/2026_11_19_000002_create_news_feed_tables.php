<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('news_sources', function (Blueprint $table) {
            $table->string('feed_url', 1024)->nullable();
            $table->timestamp('next_poll_at')->nullable();
            $table->uuid('poll_claim')->nullable();
            $table->unsignedSmallInteger('poll_failures')->default(0);
            $table->index(['enabled', 'next_poll_at']);
        });

        Schema::create('news_feed_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained('news_sources')->cascadeOnDelete();
            $table->char('source_hash', 64);
            $table->string('source_url', 1024);
            $table->string('title', 220);
            $table->date('source_date')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
            $table->unique(['source_id', 'source_hash']);
            $table->index('source_hash');
        });

        Schema::create('news_feed_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained('news_sources')->cascadeOnDelete();
            $table->string('status', 20);
            $table->unsignedSmallInteger('new_candidates')->default(0);
            $table->string('detail', 150)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('news_feed_checks');
        Schema::dropIfExists('news_feed_candidates');
        Schema::table('news_sources', function (Blueprint $table) {
            $table->dropIndex(['enabled', 'next_poll_at']);
            $table->dropColumn(['feed_url', 'next_poll_at', 'poll_claim', 'poll_failures']);
        });
    }
};
