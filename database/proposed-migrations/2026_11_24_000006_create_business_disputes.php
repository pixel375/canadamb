<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_disputes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->foreignId('claimant_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('site_id')->constrained()->restrictOnDelete();
            $table->string('contact_email', 254);
            $table->text('explanation');
            $table->string('status', 24)->default('pending');
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('review_note', 1000)->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'status']);
            $table->index(['claimant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_disputes');
    }
};
