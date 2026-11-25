<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('news_candidate_reviews', function (Blueprint $table) {
            $table->foreignId('candidate_id')->primary()->constrained('news_feed_candidates')->cascadeOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('dismissed')->default(false);
            $table->string('reason', 300);
            $table->timestamp('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('news_candidate_reviews');
    }
};
