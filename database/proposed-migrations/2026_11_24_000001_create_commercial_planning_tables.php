<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('advertising_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->foreignId('site_id')->constrained()->restrictOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('placement', 32);
            $table->string('title', 120);
            $table->string('body', 500);
            $table->date('starts_on');
            $table->unsignedTinyInteger('weeks');
            $table->unsignedBigInteger('estimated_cents')->nullable();
            $table->unsignedBigInteger('rate_revision_id');
            $table->string('status', 24)->default('pending');
            $table->string('review_note', 1200)->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'status']);
            $table->index(['site_id', 'placement', 'status']);
        });
        Schema::create('business_entitlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('product', 32);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'product', 'ends_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_entitlements');
        Schema::dropIfExists('advertising_campaigns');
    }
};
