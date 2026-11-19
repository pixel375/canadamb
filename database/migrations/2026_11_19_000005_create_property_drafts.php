<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_listings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('location_id')->constrained('locations');
            $table->string('title', 140);
            $table->text('description');
            $table->string('transaction', 12);
            $table->string('property_type', 24);
            $table->string('seller_type', 24);
            $table->unsignedBigInteger('price_cents');
            $table->unsignedSmallInteger('bedrooms')->nullable();
            $table->decimal('bathrooms', 4, 1)->nullable();
            $table->unsignedInteger('floor_area')->nullable();
            $table->decimal('acreage', 12, 4)->nullable();
            $table->unsignedSmallInteger('year_built')->nullable();
            $table->date('available_on')->nullable();
            $table->boolean('pets_allowed')->nullable();
            $table->string('private_address', 240)->nullable();
            $table->string('legal_land_description', 240)->nullable();
            $table->string('status', 20)->default('draft');
            $table->text('review_note')->nullable();
            $table->timestamps();
            $table->index(['owner_id', 'location_id', 'status']);
            $table->index(['status', 'transaction', 'property_type']);
        });

        Schema::create('property_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_listing_id')->constrained()->cascadeOnDelete();
            $table->string('image_path')->unique();
            $table->string('thumbnail_path')->unique();
            $table->unsignedSmallInteger('position');
            $table->unique(['property_listing_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_photos');
        Schema::dropIfExists('property_listings');
    }
};
