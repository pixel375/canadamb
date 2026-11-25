<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commercial_payment_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('advertising_campaigns')->restrictOnDelete();
            $table->char('reference', 36)->unique();
            $table->string('provider', 24)->default('stripe-test');
            $table->string('provider_session_id')->nullable()->unique();
            $table->unsignedBigInteger('amount_cents');
            $table->char('currency', 3)->default('cad');
            $table->string('status', 24)->default('created');
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();
        });
        Schema::create('commercial_payment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attempt_id')->constrained('commercial_payment_attempts')->restrictOnDelete();
            $table->string('provider_event_id')->unique();
            $table->string('event_type', 80);
            $table->char('payload_sha256', 64);
            $table->string('outcome', 24);
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commercial_payment_events');
        Schema::dropIfExists('commercial_payment_attempts');
    }
};
