<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domains', fn (Blueprint $table) => $table->uuid('oauth_client_id')->nullable()->unique());
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropUnique(['oauth_client_id']);
            $table->dropColumn('oauth_client_id');
        });
    }
};
