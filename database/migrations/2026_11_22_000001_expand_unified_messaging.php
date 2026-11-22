<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['business_locations', 'job_postings'] as $context) {
            Schema::table($context, function (Blueprint $table) {
                $table->foreignId('message_contact_user_id')->nullable()->constrained('users')->nullOnDelete();
            });
        }

        Schema::table('conversations', function (Blueprint $table) {
            $table->string('context_type', 16)->default('marketplace');
            $table->foreignId('business_location_id')->nullable();
            $table->foreignId('job_posting_id')->nullable();
            $table->foreignId('location_id')->nullable();
            $table->foreignId('listing_id')->nullable()->change();
            $table->foreign('business_location_id')->references('id')->on('business_locations')->restrictOnDelete();
            $table->foreign('job_posting_id')->references('id')->on('job_postings')->restrictOnDelete();
            $table->foreign('location_id')->references('id')->on('locations')->restrictOnDelete();
            $table->unique(['business_location_id', 'buyer_id', 'seller_id']);
            $table->unique(['job_posting_id', 'buyer_id', 'seller_id']);
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->uuid('idempotency_key')->nullable();
            $table->unique(['sender_id', 'idempotency_key']);
        });

        Schema::create('message_reads', function (Blueprint $table) {
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('read_at')->useCurrent();
            $table->primary(['message_id', 'user_id']);
            $table->index(['user_id', 'message_id']);
        });

        Schema::create('message_send_replays', function (Blueprint $table) {
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->uuid('idempotency_key');
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->primary(['sender_id', 'idempotency_key']);
        });

        Schema::create('message_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->string('path')->unique();
            $table->string('original_name');
            $table->string('mime_type', 127);
            $table->unsignedBigInteger('size_bytes');
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('conversation_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reporter_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('message_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason', 500);
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['conversation_id', 'reporter_id']);
        });

        Schema::table('member_settings', function (Blueprint $table) {
            $table->boolean('notify_business_messages')->default(true);
            $table->boolean('notify_job_messages')->default(true);
        });
    }

    public function down(): void
    {
        if (DB::table('conversations')->where('context_type', '!=', 'marketplace')->orWhereNull('listing_id')->exists()
            || DB::table('message_attachments')->exists() || DB::table('conversation_reports')->exists()
            || DB::table('message_reads')->exists()) {
            throw new RuntimeException('Messaging data requires this schema. Review a forward fix or restore a verified backup.');
        }

        Schema::dropIfExists('conversation_reports');
        Schema::dropIfExists('message_attachments');
        Schema::dropIfExists('message_send_replays');
        Schema::dropIfExists('message_reads');

        Schema::table('messages', function (Blueprint $table) {
            $table->dropUnique(['sender_id', 'idempotency_key']);
            $table->dropColumn('idempotency_key');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropForeign(['business_location_id']);
            $table->dropForeign(['job_posting_id']);
            $table->dropForeign(['location_id']);
            $table->dropUnique(['business_location_id', 'buyer_id', 'seller_id']);
            $table->dropUnique(['job_posting_id', 'buyer_id', 'seller_id']);
            $table->dropColumn(['context_type', 'business_location_id', 'job_posting_id', 'location_id']);
            $table->foreignId('listing_id')->change();
        });

        foreach (['business_locations', 'job_postings'] as $context) {
            Schema::table($context, function (Blueprint $table) {
                $table->dropConstrainedForeignId('message_contact_user_id');
            });
        }

        Schema::table('member_settings', function (Blueprint $table) {
            $table->dropColumn(['notify_business_messages', 'notify_job_messages']);
        });
    }
};
