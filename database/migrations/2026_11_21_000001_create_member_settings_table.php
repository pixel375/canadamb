<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_settings', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('bio', 500)->nullable();
            $table->foreignId('preferred_location_id')->nullable()->index()->constrained('locations')->nullOnDelete();
            $table->boolean('show_bio')->default(false);
            $table->boolean('show_city')->default(false);
            $table->boolean('show_listings')->default(true);
            $table->boolean('notify_discussion_replies')->default(true);
            $table->boolean('notify_marketplace_messages')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_settings');
    }
};
