<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('community_events', function (Blueprint $table) {
            $table->foreignId('thread_id')->nullable()->unique()->constrained('community_threads')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('community_events')->whereNotNull('thread_id')->exists()) {
            throw new RuntimeException('Linked events require this schema. Restore a verified backup or roll forward.');
        }
        Schema::table('community_events', function (Blueprint $table) {
            $table->dropForeign(['thread_id']);
            $table->dropUnique(['thread_id']);
            $table->dropColumn('thread_id');
        });
    }
};
