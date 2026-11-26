<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('owner_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->unsignedBigInteger('target_id');
            $table->string('decision', 20);
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at');
            $table->index(['user_id', 'read_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('owner_notifications');
    }
};
