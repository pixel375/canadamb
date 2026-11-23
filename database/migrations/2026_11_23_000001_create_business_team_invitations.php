<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_team_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->restrictOnDelete();
            $table->foreignId('invited_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('invited_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 16);
            $table->string('status', 16)->default('pending');
            $table->timestamp('expires_at');
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'status', 'invited_user_id'], 'team_invitation_business_idx');
            $table->index(['invited_user_id', 'status', 'expires_at'], 'team_invitation_recipient_idx');
        });
    }

    public function down(): void
    {
        if (DB::table('business_team_invitations')->exists()) {
            throw new RuntimeException('Staff invitation and acceptance history requires this schema. Roll forward or restore a verified backup.');
        }
        Schema::dropIfExists('business_team_invitations');
    }
};
