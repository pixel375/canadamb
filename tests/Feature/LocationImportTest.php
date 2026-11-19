<?php

namespace Tests\Feature;

use App\Models\Location;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocationImportTest extends TestCase
{
    use RefreshDatabase;

    protected $seeder = DatabaseSeeder::class;

    public function test_import_is_explicit_and_keeps_the_existing_brandon_id(): void
    {
        $brandon = Location::where('slug', 'brandon')->sole();
        $file = tempnam(sys_get_temp_dir(), 'canadamb-cgn-');
        try {
            file_put_contents($file, "CGNDB ID,Geographical Name,Generic Term,Latitude,Longitude,Province - Territory\nGADMZ,Brandon,City,49.83,-99.95,Manitoba\nTST01,Another locality,Locality,50.1,-99.1,Manitoba\nTST02,A lake,Lake,50.1,-99.1,Manitoba\nTST03,Outside,Town,52.1,-99.1,Saskatchewan\n");
            $this->artisan('locations:import-cgn', ['file' => $file])->assertSuccessful();
            $this->assertDatabaseMissing('locations', ['source_key' => 'TST01']);
            for ($i = 0; $i < 2; $i++) {
                $this->artisan('locations:import-cgn', ['file' => $file, '--apply' => true])->assertSuccessful();
            }
        } finally {
            unlink($file);
        }

        $this->assertDatabaseHas('locations', ['id' => $brandon->id, 'source_key' => 'GADMZ']);
        $this->assertDatabaseHas('locations', ['slug' => 'another-locality-tst01', 'source_key' => 'TST01']);
        $this->assertDatabaseMissing('locations', ['source_key' => 'TST02']);
        $this->assertDatabaseMissing('locations', ['source_key' => 'TST03']);
        $this->assertSame(1, Location::where('source_key', 'TST01')->count());
    }
}
