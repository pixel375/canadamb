<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('news_sources', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('url');
            $table->foreignId('coverage_location_id')->nullable()->constrained('locations');
            $table->string('reuse', 30);
            $table->text('reuse_notes')->nullable();
            $table->boolean('enabled')->default(false);
            $table->timestamps();
        });

        Schema::create('news_articles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained('news_sources');
            $table->foreignId('location_id')->nullable()->constrained();
            $table->string('external_key', 100);
            $table->string('source_url', 1024);
            $table->char('source_hash', 64)->unique();
            $table->char('input_hash', 64);
            $table->date('source_date');
            $table->string('title', 140);
            $table->text('body');
            $table->string('category', 30);
            $table->string('status', 20)->default('draft');
            $table->boolean('sensitive')->default(false);
            $table->text('verification')->nullable();
            $table->string('corroboration_url', 1024)->nullable();
            $table->foreignId('editor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['source_id', 'external_key']);
            $table->index(['status', 'location_id', 'published_at']);
            $table->index(['status', 'published_at']);
        });

        Schema::create('news_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained('news_articles')->cascadeOnDelete();
            $table->foreignId('editor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('snapshot');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('news_revisions');
        Schema::dropIfExists('news_articles');
        Schema::dropIfExists('news_sources');
    }
};
