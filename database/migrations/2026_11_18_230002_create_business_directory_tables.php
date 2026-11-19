<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('businesses', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name')->index();
            $table->string('category', 64)->index();
            $table->text('description')->nullable();
            $table->string('website')->nullable();
            $table->string('public_email')->nullable();
            $table->string('phone', 32)->nullable();
            $table->text('hours')->nullable();
            $table->string('status', 24)->default('pending')->index();
            $table->timestamps();
        });
        Schema::create('business_sources', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('url');
            $table->string('license');
            $table->timestamps();
        });
        Schema::create('business_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained();
            $table->foreignId('source_id')->nullable()->constrained('business_sources');
            $table->string('external_key', 100)->nullable();
            $table->string('address');
            $table->char('address_key', 64)->nullable();
            $table->string('location_status', 24)->default('user_selected');
            $table->timestamp('imported_at')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamps();
            $table->unique(['source_id', 'external_key']);
            $table->unique(['location_id', 'address_key']);
            $table->index(['location_id', 'business_id']);
        });
        Schema::create('business_memberships', function (Blueprint $table) {
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20);
            $table->timestamps();
            $table->primary(['business_id', 'user_id']);
        });
        Schema::create('business_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('contact_email');
            $table->text('evidence');
            $table->string('status', 24)->default('pending')->index();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'status']);
        });
        Schema::create('business_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_location_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 24);
            $table->string('title', 140);
            $table->text('body');
            $table->string('status', 24)->default('pending');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'published_at']);
        });
        Schema::create('business_import_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained('business_sources');
            $table->string('status', 24);
            $table->unsignedInteger('created')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['business_import_runs', 'business_updates', 'business_claims', 'business_memberships', 'business_locations', 'business_sources', 'businesses'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
