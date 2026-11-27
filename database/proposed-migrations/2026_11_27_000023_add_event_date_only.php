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
            $table->boolean('date_only')->default(false);
        });
    }

    public function down(): void
    {
        if (DB::table('community_events')->where('date_only', true)->exists()) {
            throw new RuntimeException('Review date-only event history before reversing this migration.');
        }
        Schema::table('community_events', function (Blueprint $table) {
            $table->dropColumn('date_only');
        });
    }
};
