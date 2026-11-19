<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HostingerStagingTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_staging_keeps_both_public_portals_out_of_search_and_shared_caches(): void
    {
        config(['network.staging' => true]);

        foreach (['canadamb.ca', 'brandonmb.ca'] as $host) {
            $this->get("https://{$host}/")
                ->assertOk()
                ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
                ->assertHeader('Cache-Control', 'no-store, private');
            $this->get("https://{$host}/robots.txt")
                ->assertOk()
                ->assertDontSee('Sitemap:', false);
        }
    }

    public function test_deployment_check_rejects_unmarked_and_high_privilege_databases(): void
    {
        $originalDriver = config('database.default');
        config(['network.staging' => false, 'database.default' => 'mariadb']);
        DB::shouldReceive('getConnections')->andReturn([]);
        DB::shouldReceive('selectOne')->never();

        $this->artisan('network:deployment-check', ['--staging-database' => 'canadamb_staging'])
            ->expectsOutput('Staging requires a staging-marked environment and an explicitly named MariaDB staging database.')
            ->assertFailed();

        config(['network.staging' => true]);
        DB::shouldReceive('selectOne')->once()->with('SELECT DATABASE() AS name')->andReturn((object) ['name' => 'canadamb_staging']);
        DB::shouldReceive('select')->once()->with('SHOW GRANTS')->andReturn([(object) ['grants' => 'GRANT ALL PRIVILEGES ON *.* TO `runtime`@`localhost`']]);

        $this->artisan('network:deployment-check', ['--staging-database' => 'canadamb_staging'])
            ->expectsOutput('Use a database user with grants restricted to the selected deployment database.')
            ->assertFailed();

        config(['database.default' => $originalDriver]);
    }
}
