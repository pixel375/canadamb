<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_reports', function (Blueprint $table) {
            $table->id();
            $table->string('source_type', 20);
            $table->unsignedBigInteger('source_id');
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reporter_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 500);
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['source_type', 'source_id', 'reporter_id']);
            $table->index(['created_at', 'id']);
            $table->index(['location_id', 'created_at']);
        });

        Schema::create('moderation_report_decisions', function (Blueprint $table) {
            $table->id();
            $table->string('report_type', 20);
            $table->unsignedBigInteger('report_id');
            $table->string('decision', 12);
            $table->string('note', 240);
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('audit_log_id')->constrained()->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['report_type', 'report_id']);
        });
    }

    public function down(): void
    {
        if (DB::table('content_reports')->exists() || DB::table('moderation_report_decisions')->exists()) {
            throw new RuntimeException('Moderation data requires this schema. Review a forward fix or restore a verified backup.');
        }

        Schema::dropIfExists('moderation_report_decisions');
        Schema::dropIfExists('content_reports');
    }
};
