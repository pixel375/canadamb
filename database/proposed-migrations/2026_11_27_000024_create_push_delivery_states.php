<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_delivery_states', function (Blueprint $table) {
            $table->foreignId('device_id')->primary()->constrained('push_devices')->cascadeOnDelete();
            $table->unsignedBigInteger('message_cursor')->default(0);
            $table->unsignedBigInteger('discussion_cursor')->default(0);
            $table->unsignedBigInteger('owner_cursor')->default(0);
            $table->timestamp('next_check_at')->index();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedBigInteger('accepted_count')->default(0);
            $table->timestamp('last_accepted_at')->nullable();
            $table->string('last_result', 24)->default('baseline');
            $table->timestamp('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_delivery_states');
    }
};
