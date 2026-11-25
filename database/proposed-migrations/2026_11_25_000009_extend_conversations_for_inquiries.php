<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->unsignedBigInteger('listing_id')->nullable()->change();
            $table->char('inquiry_key', 64)->nullable()->unique();
            $table->foreignId('inquiry_business_id')->nullable()->constrained('businesses')->restrictOnDelete();
            $table->foreignId('inquiry_job_id')->nullable()->constrained('job_postings')->restrictOnDelete();
            $table->string('inquiry_title', 140)->nullable();
            $table->timestamp('buyer_archived_at')->nullable();
            $table->timestamp('seller_archived_at')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('conversations')->whereNull('listing_id')->exists()) {
            throw new RuntimeException('Export and review inquiry history before reversing this migration.');
        }
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropForeign(['inquiry_business_id']);
            $table->dropForeign(['inquiry_job_id']);
            $table->dropUnique(['inquiry_key']);
            $table->dropColumn(['inquiry_key', 'inquiry_business_id', 'inquiry_job_id', 'inquiry_title', 'buyer_archived_at', 'seller_archived_at']);
            $table->unsignedBigInteger('listing_id')->nullable(false)->change();
        });
    }
};
