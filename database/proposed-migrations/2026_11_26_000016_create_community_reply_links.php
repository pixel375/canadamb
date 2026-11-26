<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('community_reply_links', function (Blueprint $table) {
            $table->foreignId('reply_id')->primary()->constrained('community_replies')->cascadeOnDelete();
            $table->foreignId('parent_reply_id')->constrained('community_replies')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('community_reply_links');
    }
};
