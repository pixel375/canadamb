<?php

use App\Support\BusinessDirectory;
use App\Support\BusinessImports\BusinessCsv;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_sources', function (Blueprint $table) {
            $table->unsignedInteger('revision')->default(0);
            $table->unsignedInteger('approved_revision')->nullable();
            $table->string('terms_url', 512)->nullable();
            $table->date('data_as_of')->nullable();
        });
        Schema::table('businesses', function (Blueprint $table) {
            $table->char('import_name_key', 64)->nullable()->index('business_import_name_idx');
            $table->foreignId('website_source_id')->nullable()->constrained('business_sources')->restrictOnDelete();
            $table->char('imported_website_hash', 64)->nullable();
        });
        Schema::table('business_locations', function (Blueprint $table) {
            $table->char('import_key', 64)->nullable();
            $table->char('imported_address_hash', 64)->nullable();
            $table->unsignedBigInteger('last_import_run_id')->nullable();
            $table->timestamp('import_stale_at')->nullable();
            $table->index(['source_id', 'import_key'], 'business_branch_import_idx');
        });
        Schema::table('business_import_runs', function (Blueprint $table) {
            $table->unsignedInteger('source_revision')->nullable();
            $table->char('input_sha256', 64)->nullable();
            $table->char('review_token', 64)->nullable();
            $table->boolean('full_snapshot')->default(false);
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('staged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
        });
        Schema::create('business_import_identities', function (Blueprint $table) {
            $table->foreignId('source_id')->constrained('business_sources')->restrictOnDelete();
            $table->string('identity_key', 100);
            $table->foreignId('business_id')->constrained('businesses')->restrictOnDelete();
            $table->primary(['source_id', 'identity_key']);
        });
        Schema::create('business_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('business_import_runs')->restrictOnDelete();
            $table->unsignedInteger('row_number');
            $table->string('group_key', 102)->nullable();
            $table->char('branch_key', 64)->nullable();
            $table->char('name_key', 64)->nullable();
            $table->char('physical_key', 64)->nullable();
            $table->json('payload')->nullable();
            $table->string('decision', 32);
            $table->json('review')->nullable();
            $table->json('details')->nullable();
            $table->unsignedBigInteger('result_business_id')->nullable();
            $table->unsignedBigInteger('result_branch_id')->nullable();
            $table->unique(['run_id', 'row_number'], 'business_import_row_number_uq');
            $table->index(['run_id', 'group_key', 'id'], 'business_import_group_idx');
            $table->index(['run_id', 'branch_key'], 'business_import_branch_idx');
            $table->index(['run_id', 'name_key'], 'business_import_row_name_idx');
            $table->index(['run_id', 'physical_key'], 'business_import_physical_idx');
        });
        Schema::create('business_import_mutex', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('generation')->default(0);
        });
        DB::table('business_import_mutex')->insert(['id' => 1]);

        DB::table('businesses')->select('id', 'name')->chunkById(500, function ($rows): void {
            foreach ($rows as $row) {
                DB::table('businesses')->where('id', $row->id)->update(['import_name_key' => BusinessDirectory::identityNameKey($row->name)]);
            }
        });
        DB::table('business_locations')->whereNotNull('external_key')->select('id', 'external_key')->chunkById(500, function ($rows): void {
            foreach ($rows as $row) {
                DB::table('business_locations')->where('id', $row->id)->update(['import_key' => BusinessCsv::branchKey($row->external_key)]);
            }
        });
    }

    public function down(): void
    {
        if (DB::table('business_import_runs')->whereNotNull('input_sha256')->exists()
            || DB::table('business_sources')->where('revision', '>', 0)->exists()
            || DB::table('business_import_identities')->exists()) {
            throw new RuntimeException('Business source and import history require this schema. Roll forward or restore a verified backup.');
        }
        Schema::drop('business_import_rows');
        Schema::drop('business_import_identities');
        Schema::drop('business_import_mutex');
        Schema::table('business_import_runs', function (Blueprint $table) {
            $table->dropForeign(['staged_by']);
            $table->dropForeign(['applied_by']);
            $table->dropColumn(['source_revision', 'input_sha256', 'review_token', 'full_snapshot', 'expires_at', 'staged_by', 'applied_by']);
        });
        Schema::table('business_locations', function (Blueprint $table) {
            $table->dropIndex('business_branch_import_idx');
            $table->dropColumn(['import_key', 'imported_address_hash', 'last_import_run_id', 'import_stale_at']);
        });
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropIndex('business_import_name_idx');
            $table->dropForeign(['website_source_id']);
            $table->dropColumn(['import_name_key', 'website_source_id', 'imported_website_hash']);
        });
        Schema::table('business_sources', fn (Blueprint $table) => $table->dropColumn(['revision', 'approved_revision', 'terms_url', 'data_as_of']));
    }
};
