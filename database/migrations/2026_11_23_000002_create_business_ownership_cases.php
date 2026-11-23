<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_ownership_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->restrictOnDelete();
            $table->string('type', 16);
            $table->string('status', 24);
            $table->foreignId('initiated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('outgoing_owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('proposed_owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('evidence');
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('proof_method', 32)->nullable();
            $table->text('proof_reference')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'type', 'status'], 'ownership_cases_business_idx');
            $table->index(['proposed_owner_id', 'type', 'status'], 'ownership_cases_recipient_idx');
            $table->index(['status', 'type', 'created_at'], 'ownership_cases_review_idx');
        });
    }

    public function down(): void
    {
        if (DB::table('business_ownership_cases')->exists()) {
            throw new RuntimeException('Private ownership case and review history requires this schema. Use a forward migration or verified restore.');
        }

        Schema::dropIfExists('business_ownership_cases');
    }
};
