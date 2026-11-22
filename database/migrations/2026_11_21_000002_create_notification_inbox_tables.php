<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('event', 40);
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('resource_id');
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['user_id', 'event', 'source_id'], 'notification_source_unique');
            $table->index(['user_id', 'created_at', 'id'], 'notification_recipient_order');
            $table->index(['user_id', 'read_at', 'created_at', 'id'], 'notification_recipient_read');
        });

        Schema::create('notification_locations', function (Blueprint $table) {
            $table->foreignId('notification_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->primary(['notification_id', 'location_id']);
            $table->index(['location_id', 'notification_id'], 'notification_location_scope');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_locations');
        Schema::dropIfExists('notifications');
    }
};
