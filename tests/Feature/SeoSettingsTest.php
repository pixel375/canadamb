<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeoSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_filtered_discovery_pages_do_not_create_indexable_variants(): void
    {
        config(['network.prelaunch' => false, 'network.staging' => false]);
        foreach (['https://canadamb.ca', 'https://brandonmb.ca'] as $host) {
            $this->get($host.'/marketplace')->assertOk()
                ->assertSee('<link rel="canonical" href="'.$host.'/marketplace">', false)
                ->assertDontSee('<meta name="robots" content="noindex, nofollow">', false)
                ->assertSee('<meta property="og:locale" content="en_CA">', false)
                ->assertSee('<meta name="twitter:card" content="summary">', false);
            foreach (['/marketplace?group=vehicles', '/marketplace?view=all&page=2', '/businesses?category=services', '/jobs?type=full-time', '/events?location='] as $path) {
                $this->get($host.$path)->assertOk()
                    ->assertSee('<meta name="robots" content="noindex, nofollow">', false);
            }
        }
        $this->get('https://brandonmb.ca/events?location%5B%5D=invalid')->assertOk()
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false)
            ->assertDontSee('<link rel="canonical" href="https://brandonmb.ca/events">', false);
    }

    public function test_metadata_is_audited_and_does_not_change_indexing_or_other_portals(): void
    {
        config(['network.prelaunch' => true, 'network.prelaunch_ips' => ['127.0.0.1']]);
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1']);
        $site = Site::where('code', 'network')->sole();
        $url = 'https://canadamb.ca/admin/seo/'.$site->id;
        $data = ['version' => $site->configurationVersion(), 'seo_title' => 'Manitoba community test title', 'seo_description' => 'Manitoba community test description.'];
        $this->actingAs(User::factory()->create(['role' => 'network_admin']))->post($url, $data)->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'super_admin']))->get('https://canadamb.ca/admin/seo')->assertOk();
        $this->post($url, $data)->assertRedirect('/admin/seo')->assertSessionHasNoErrors();
        $this->post($url, $data)->assertSessionHasErrors('version');
        $this->assertDatabaseHas('audit_logs', ['action' => 'site.seo.updated', 'site_id' => $site->id]);
        $this->get('https://canadamb.ca/')->assertOk()->assertSee('<title>Manitoba community test title</title>', false)
            ->assertSee('Manitoba community test description.')->assertSee('noindex, nofollow');
        $this->get('https://brandonmb.ca/')->assertOk()->assertDontSee('Manitoba community test title');
    }
}
