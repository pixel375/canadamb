<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('property_listings', function (Blueprint $table) {
            $table->string('brokerage_name', 150)->nullable();
            $table->string('registration_reference', 100)->nullable();
            $table->string('authorization_capacity', 24)->nullable();
            $table->timestamp('authorization_attested_at')->nullable();
            $table->timestamp('review_checklist_completed_at')->nullable();
            $table->timestamp('publication_expires_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->index(['status', 'publication_expires_at'], 'property_public_expiry');
        });
    }

    public function down(): void
    {
        Schema::table('property_listings', function (Blueprint $table) {
            $table->dropIndex('property_public_expiry');
            $table->dropColumn([
                'brokerage_name', 'registration_reference', 'authorization_capacity',
                'authorization_attested_at', 'review_checklist_completed_at',
                'publication_expires_at', 'withdrawn_at',
            ]);
        });
    }
};
