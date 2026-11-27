<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('direct_message_preferences', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->boolean('member_messages')->default(false);
            $table->boolean('official_messages')->default(false);
            $table->timestamp('updated_at');
        });
        Schema::table('conversations', function (Blueprint $table) {
            $table->string('direct_kind', 16)->nullable();
            $table->index(['buyer_id', 'direct_kind', 'created_at']);
        });
    }

    public function down(): void
    {
        if (DB::table('conversations')->whereNotNull('direct_kind')->exists()) {
            throw new RuntimeException('Export and review direct conversation history before reversing this migration.');
        }
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex(['buyer_id', 'direct_kind', 'created_at']);
            $table->dropColumn('direct_kind');
        });
        Schema::dropIfExists('direct_message_preferences');
    }
};
