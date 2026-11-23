<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_locations', function (Blueprint $table) {
            $table->string('operational_status', 24)->default('open')->index('business_branch_operational_idx');
        });
        Schema::create('business_branch_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->restrictOnDelete();
            $table->foreignId('business_location_id')->nullable()->constrained('business_locations')->restrictOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('locations')->restrictOnDelete();
            $table->foreignId('requested_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 24);
            $table->string('status', 24)->default('pending');
            $table->string('proposed_address')->nullable();
            $table->string('original_address')->nullable();
            $table->string('original_status', 24)->nullable();
            $table->text('evidence');
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('proof_reference')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'status'], 'business_branch_request_status_idx');
            $table->index(['status', 'created_at'], 'business_branch_request_queue_idx');
        });
    }

    public function down(): void
    {
        if (DB::table('business_branch_requests')->exists()
            || DB::table('business_locations')->where('operational_status', '!=', 'open')->exists()) {
            throw new RuntimeException('Reviewed branch changes and closure history must survive. Roll forward or restore a reviewed backup.');
        }
        Schema::dropIfExists('business_branch_requests');
        Schema::table('business_locations', function (Blueprint $table) {
            $table->dropIndex('business_branch_operational_idx');
            $table->dropColumn('operational_status');
        });
    }
};
