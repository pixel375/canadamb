<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_series_occurrences', function (Blueprint $table) {
            $table->foreignId('event_id')->primary()->constrained('community_events')->cascadeOnDelete();
            $table->uuid('series_key');
            $table->unsignedTinyInteger('position');
            $table->unsignedTinyInteger('original_count');
            $table->string('frequency', 12);
            $table->unique(['series_key', 'position']);
        });
    }

    public function down(): void
    {
        if (DB::table('event_series_occurrences')->exists()) {
            throw new RuntimeException('Review recurring series history before reversing this migration.');
        }
        Schema::dropIfExists('event_series_occurrences');
    }
};
