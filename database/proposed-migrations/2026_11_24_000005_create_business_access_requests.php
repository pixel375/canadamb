<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_access_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->foreignId('requester_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('recipient_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 20);
            $table->string('status', 20)->default('pending');
            $table->timestamp('expires_at');
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('review_note', 1000)->nullable();
            $table->timestamps();
            $table->index(['business_id', 'status']);
            $table->index(['recipient_id', 'status']);
            $table->index(['requester_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_access_requests');
    }
};
