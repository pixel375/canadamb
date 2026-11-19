<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 32)->default('member')->index();
            $table->unsignedBigInteger('session_version')->default(0);
        });

        Schema::create('location_sources', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('url')->nullable();
            $table->string('license');
            $table->timestamp('imported_at')->nullable();
        });

        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained('location_sources');
            $table->foreignId('parent_id')->nullable()->constrained('locations');
            $table->string('source_key')->nullable();
            $table->string('name')->index();
            $table->string('slug')->unique();
            $table->string('type', 40)->index();
            $table->char('province', 2)->default('MB');
            $table->decimal('latitude', 9, 6)->nullable();
            $table->decimal('longitude', 9, 6)->nullable();
            $table->boolean('selectable')->default(true);
            $table->timestamps();
            $table->unique(['source_id', 'source_key']);
        });

        Schema::create('location_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->string('name')->index();
            $table->unique(['location_id', 'name']);
        });

        Schema::create('location_boundaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_id')->constrained('location_sources');
            $table->json('geometry');
            $table->timestamp('imported_at');
        });

        Schema::create('sites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->nullable()->unique()->constrained();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('subtitle');
            $table->string('tagline');
            $table->text('description');
            $table->string('status', 24)->index();
            $table->string('seo_title')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('logo_url')->nullable();
            $table->json('accent');
            $table->json('overrides')->nullable();
            $table->timestamps();
        });

        Schema::create('domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('host', 253)->unique();
            $table->timestamp('dns_confirmed_at')->nullable();
            $table->timestamp('tls_confirmed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('configuration_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('settings');
            $table->timestamps();
        });

        Schema::create('network_configuration', function (Blueprint $table) {
            $table->id();
            $table->foreignId('published_id')->constrained('configuration_revisions');
            $table->foreignId('previous_id')->nullable()->constrained('configuration_revisions');
            $table->foreignId('draft_id')->nullable()->constrained('configuration_revisions');
            $table->unsignedBigInteger('version')->default(1);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 80);
            $table->json('context');
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        foreach (['audit_logs', 'network_configuration', 'configuration_revisions', 'domains', 'sites', 'location_boundaries', 'location_aliases', 'locations', 'location_sources'] as $name) {
            Schema::dropIfExists($name);
        }

        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['role', 'session_version']));
    }
};
