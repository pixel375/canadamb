<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listing_categories', function (Blueprint $table) {
            $table->string('slug', 64)->primary();
            $table->string('name');
        });

        Schema::create('listings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained();
            $table->string('category', 64);
            $table->foreign('category')->references('slug')->on('listing_categories');
            $table->string('title', 120);
            $table->text('description');
            $table->unsignedBigInteger('price_cents');
            $table->boolean('negotiable')->default(false);
            $table->string('condition', 24);
            $table->string('status', 24)->default('pending');
            $table->json('attributes')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'location_id', 'published_at']);
            $table->index(['status', 'published_at']);
            $table->index(['status', 'expires_at']);
            $table->index(['category', 'status', 'price_cents']);
        });

        Schema::create('listing_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('previous_status', 24)->nullable();
            $table->string('status', 24);
            $table->string('reason', 240)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_status_history');
        Schema::dropIfExists('listings');
        Schema::dropIfExists('listing_categories');
    }
};
