<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete()->unique();
            $table->string('image_path')->unique();
            $table->string('thumbnail_path')->unique();
            $table->timestamps();
        });
        Schema::create('business_inquiry_settings', function (Blueprint $table) {
            $table->foreignId('business_id')->constrained()->cascadeOnDelete()->primary();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('business_inquiries')->default(false);
            $table->boolean('job_inquiries')->default(false);
            $table->timestamp('updated_at');
        });
    }

    public function down(): void
    {
        if (DB::table('message_attachments')->exists() || DB::table('business_inquiry_settings')->exists()) {
            throw new RuntimeException('Review attachment retention and inquiry preferences before reversing this migration.');
        }
        Schema::dropIfExists('business_inquiry_settings');
        Schema::dropIfExists('message_attachments');
    }
};
