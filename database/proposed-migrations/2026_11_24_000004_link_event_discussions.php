<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('community_event_discussions', function (Blueprint $table) {
            $table->foreignId('event_id')->primary()->constrained('community_events')->cascadeOnDelete();
            $table->foreignId('thread_id')->unique()->constrained('community_threads')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('community_event_discussions');
    }
};
