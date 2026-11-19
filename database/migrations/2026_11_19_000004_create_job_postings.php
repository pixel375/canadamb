<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_postings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_location_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 140);
            $table->text('description');
            $table->string('employment_type', 32);
            $table->string('workplace', 16);
            $table->string('apply_url', 1024);
            $table->date('closing_on');
            $table->string('pay_unit', 12)->nullable();
            $table->unsignedBigInteger('pay_min')->nullable();
            $table->unsignedBigInteger('pay_max')->nullable();
            $table->string('status', 16)->default('pending');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'closing_on', 'published_at']);
            $table->index(['business_location_id', 'status']);
        });

        Schema::create('job_posting_bookmarks', function (Blueprint $table) {
            $table->foreignId('job_posting_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->primary(['job_posting_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_posting_bookmarks');
        Schema::dropIfExists('job_postings');
    }
};
