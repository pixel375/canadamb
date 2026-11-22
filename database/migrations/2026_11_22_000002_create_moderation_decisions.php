<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('moderation_decisions', function (Blueprint $table) {
            $table->id();
            $table->string('type', 24);
            $table->unsignedBigInteger('resource_id');
            $table->string('action', 24);
            $table->foreignId('audit_id')->unique()->constrained('audit_logs');
            $table->unsignedBigInteger('notice_source_id')->nullable()->index();
            $table->string('reason', 300)->nullable();
            $table->string('notes', 1200)->nullable();
            $table->timestamp('created_at');
            $table->index(['type', 'resource_id', 'id'], 'moderation_decisions_resource');
        });
    }

    public function down(): void
    {
        if (DB::table('moderation_decisions')->exists()) {
            throw new RuntimeException('Preserve recorded moderation decisions. Restore a reviewed backup or roll forward.');
        }

        Schema::dropIfExists('moderation_decisions');
    }
};
