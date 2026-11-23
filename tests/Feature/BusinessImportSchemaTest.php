<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Location;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BusinessImportSchemaTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_import_identity_is_unique_within_source_and_does_not_change_business_ids(): void
    {
        $existing = Business::create(['slug' => 'historic-name', 'name' => "Cafe\u{0301}  Repair",
            'category' => 'services', 'status' => 'active']);
        $location = Location::where('slug', 'brandon')->sole();
        $source = DB::table('business_sources')->insertGetId(['name' => 'Fixture', 'url' => 'https://example.test', 'license' => 'Fixture']);
        $branch = $existing->locations()->create(['location_id' => $location->id, 'source_id' => $source,
            'external_key' => 'Branch-X', 'address' => '2 Main']);
        $this->assertDatabaseHas('businesses', ['id' => $existing->id, 'slug' => 'historic-name',
            'import_name_key' => hash('sha256', 'café repair')]);
        $this->assertDatabaseHas('business_locations', ['id' => $branch->id, 'business_id' => $existing->id,
            'external_key' => 'Branch-X', 'import_key' => hash('sha256', 'branch-x')]);
        $this->assertNull(DB::table('business_sources')->find($source)->approved_revision);

        $identity = ['source_id' => $source, 'identity_key' => 'group-1', 'business_id' => $existing->id];
        DB::table('business_import_identities')->insert($identity);
        $this->expectException(QueryException::class);
        DB::table('business_import_identities')->insert($identity);
    }
}
