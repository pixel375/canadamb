<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_searches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->json('filters');
            $table->char('filter_key', 64);
            $table->boolean('alerts')->default(false);
            $table->timestamp('checked_at');
            $table->timestamps();
            $table->unique(['user_id', 'site_id', 'filter_key']);
            $table->index(['user_id', 'site_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_searches');
    }
};
