<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('content');
            $table->unsignedInteger('bytes');
            $table->char('sha256', 64);
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        if (DB::table('message_documents')->exists()) {
            throw new RuntimeException('Review private document retention before reversing this migration.');
        }
        Schema::dropIfExists('message_documents');
    }
};
