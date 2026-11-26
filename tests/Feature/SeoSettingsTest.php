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

    public function test_metadata_is_audited_and_does_not_change_indexing_or_other_portals(): void
    {
        config(['network.prelaunch' => true]);
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
