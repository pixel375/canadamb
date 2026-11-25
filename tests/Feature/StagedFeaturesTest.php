<?php

namespace Tests\Feature;

use App\Support\StagedFeatures;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StagedFeaturesTest extends TestCase
{
    use RefreshDatabase;

    public function test_manifest_covers_every_proposal_and_detects_enabled_missing_schema(): void
    {
        $paths = glob(database_path('proposed-migrations/*.php'));
        $files = array_map(fn ($path) => basename($path, '.php'), $paths);
        $manifest = array_values(array_unique(array_column(StagedFeatures::DEFINITIONS, 0)));
        sort($files);
        sort($manifest);
        $this->assertSame($files, $manifest);
        $this->assertFalse(StagedFeatures::inspect()['job_searches']['ready']);
        $this->artisan('network:staged-features')->assertSuccessful();
        config(['network.job_searches' => true]);
        $this->assertTrue(StagedFeatures::inspect()['job_searches']['configured']);
        $this->artisan('network:staged-features')->assertFailed();
    }
}
