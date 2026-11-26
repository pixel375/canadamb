<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Site;
use App\Models\User;
use App\Support\Audit;
use App\Support\NetworkSettings;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NetworkTest extends TestCase
{
    use RefreshDatabase;

    protected $seeder = DatabaseSeeder::class;

    public function test_manitoba_clock_uses_local_timezone_and_both_navigation_surfaces(): void
    {
        $this->travelTo('2026-01-15 18:30:00 UTC');
        $this->get('https://brandonmb.ca/')->assertOk()->assertSee('local-context-bar', false)->assertSee('drawer-local-context', false)
            ->assertSee('12:30 pm')->assertSee('Manitoba time');
        $this->travelTo('2026-07-15 18:30:00 UTC');
        $this->get('https://canadamb.ca/')->assertOk()->assertSee('1:30 pm')->assertSee('Manitoba time');
    }

    public function test_each_active_domain_has_its_own_identity(): void
    {
        $this->get('https://canadamb.ca/')->assertOk()->assertSee('CanadaMB')->assertSee('#E85D75');
        $this->get('https://brandonmb.ca/')->assertOk()->assertSee('BrandonMB')->assertSee('#22A06B');
        $this->get('https://steinbachmb.ca/')->assertNotFound();
        $this->get('https://example.org/')->assertNotFound();
    }

    public function test_published_global_theme_propagates_and_can_be_rolled_back(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $url = 'https://canadamb.ca/admin/theme';
        $this->actingAs($admin)->post($url, ['preset' => 'purple', 'action' => 'draft'])->assertRedirect();
        $this->get('https://brandonmb.ca/')->assertDontSee('#7C3AED');
        $this->post("$url/publish")->assertRedirect();
        $this->get('https://brandonmb.ca/')->assertSee('#7C3AED')->assertSee('#22A06B');
        $this->get('https://canadamb.ca/')->assertSee('#7C3AED')->assertSee('#E85D75');
        $this->post("$url/rollback")->assertRedirect();
        $this->get('https://brandonmb.ca/')->assertDontSee('#7C3AED');
        $this->assertDatabaseCount('audit_logs', 3);
    }

    public function test_configuration_discard_requires_confirmation_and_current_version_without_touching_published_settings(): void
    {
        $base = 'https://canadamb.ca/admin/settings';
        $settings = app(NetworkSettings::class);
        $initial = $settings->state();
        $admin = User::factory()->create(['role' => 'super_admin']);
        $settings->saveDraft($admin->id, fn (array $current) => array_replace($current, ['weather_key_encrypted' => 'PRIVATE_REVISION_FIXTURE']));
        $draft = $settings->state();
        $version = NetworkSettings::stateVersion($draft);
        $data = ['configuration_version' => $version, 'confirm_discard' => '1'];
        $this->actingAs(User::factory()->create(['role' => 'network_admin']))->post($base.'/discard', $data)->assertForbidden();
        $this->actingAs($admin)->get($base)->assertOk()->assertSee('Configuration revision history')->assertSee('Pending draft')->assertDontSee('PRIVATE_REVISION_FIXTURE');
        $this->post($base.'/discard', ['configuration_version' => $version])->assertSessionHasErrors('confirm_discard');
        $this->post($base.'/discard', array_replace($data, ['configuration_version' => NetworkSettings::stateVersion($initial)]))->assertSessionHasErrors('configuration_version');
        $this->assertEquals($draft->draft_id, $settings->state()->draft_id);
        $this->post($base.'/discard', $data)->assertRedirect($base)->assertSessionHasNoErrors();
        $after = $settings->state();
        $this->assertNull($after->draft_id);
        $this->assertEquals($initial->published_id, $after->published_id);
        $this->assertEquals($initial->previous_id, $after->previous_id);
        $this->assertEquals($initial->version, $after->version);
        $this->assertDatabaseHas('configuration_revisions', ['id' => $draft->draft_id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'configuration.discard', 'actor_id' => $admin->id]);
        $this->post($base.'/discard', $data)->assertSessionHasErrors('configuration_version');
        $this->get($base)->assertOk()->assertSee('Historical')->assertDontSee('PRIVATE_REVISION_FIXTURE');
    }

    public function test_administration_is_provincial_and_role_protected(): void
    {
        $this->get('https://canadamb.ca/admin')->assertRedirect();
        $this->actingAs(User::factory()->create())->get('https://canadamb.ca/admin')->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'super_admin']))
            ->get('https://brandonmb.ca/admin')->assertNotFound();
    }

    public function test_safety_help_and_readiness_are_scoped_and_read_only(): void
    {
        foreach (['canadamb.ca', 'brandonmb.ca'] as $host) {
            $this->get("https://$host/safety")->assertOk()->assertSee('Safety and community guidance')->assertSee('Reporting limitations during prelaunch');
            $this->get("https://$host/privacy-overview")->assertOk()->assertSee('Full account export and automated deletion are not yet available');
            $this->get("https://$host/contact?topic=news")->assertOk()->assertSee('value="news" selected', false);
            $this->get("https://$host/contact?topic%5B%5D=invalid")->assertOk();
        }
        $this->actingAs(User::factory()->create())->get('https://canadamb.ca/admin/readiness')->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'super_admin']))->get('https://canadamb.ca/admin/readiness')
            ->assertOk()->assertSee('Read-only configuration indicators')->assertSee('Separate acceptance required')
            ->assertSee('Approved IP entries');
    }

    public function test_admin_dashboard_and_review_filters_remain_role_protected(): void
    {
        $this->actingAs(User::factory()->create())->get('https://canadamb.ca/admin/moderation?status=pending')->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'super_admin']));
        $this->get('https://canadamb.ca/admin')->assertOk()->assertSee('Moderation queues')->assertSee('Open listing reports')->assertSee('Recent administration activity')
            ->assertSee('Settings &amp; experience', false)->assertSee('Moderation policy')->assertSee('Local time &amp; weather', false)->assertSee('Ownership directory');
        $this->get('https://canadamb.ca/admin/theme')->assertOk()->assertSee('Prairie Forest')->assertSee('Warm Amber')->assertSee('Soft Rose');
        $this->actingAs(User::factory()->create(['role' => 'news_editor']))->get('https://canadamb.ca/admin/news/feed-health')->assertOk()
            ->assertSee('Editorial')->assertDontSee('Moderation policy')->assertDontSee('Media inventory');
        $this->actingAs(User::factory()->create(['role' => 'network_admin']))->get('https://canadamb.ca/admin')->assertOk()
            ->assertSee('Ownership directory')->assertDontSee('Local time &amp; weather', false);
        $this->actingAs(User::factory()->create(['role' => 'super_admin']));
        $this->get('https://canadamb.ca/admin/moderation?status=pending&q=desk')->assertOk()->assertSee('Review status');
        $this->get('https://canadamb.ca/admin/community?status=pending&q=meeting')->assertOk()->assertSee('Community review sections');
        $this->get('https://canadamb.ca/admin/jobs?status=pending&q=mechanic')->assertOk()->assertSee('Review status');
        $this->get('https://canadamb.ca/admin/audit?period=7&q=theme')->assertOk()->assertSee('audit entries');
        Audit::record('fixture.system-event', null, context: ['note' => 'PRIVATE_AUDIT_FIXTURE']);
        $reference = DB::table('audit_logs')->where('action', 'fixture.system-event')->value('id');
        $this->get('https://canadamb.ca/admin/audit?actor=system&site=network&reference='.$reference)->assertOk()
            ->assertSee('fixture.system-event')->assertDontSee('PRIVATE_AUDIT_FIXTURE');
        $this->get('https://canadamb.ca/admin/audit?actor=member&reference='.$reference)->assertOk()->assertDontSee('fixture.system-event');
        $this->getJson('https://canadamb.ca/admin/moderation?status=invalid')->assertUnprocessable();
    }

    public function test_admin_can_preview_a_new_community_but_activation_requires_readiness(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $base = 'https://canadamb.ca/admin/communities';
        $location = Location::where('slug', 'virden')->value('id');
        $this->actingAs($admin)->post($base, [
            'name' => 'VirdenMB', 'domain' => 'VIRDENMB.CA', 'location_id' => $location,
            'description' => 'Discover Virden.', 'accent_type' => 'solid', 'accent_colors' => '#B45309',
            'accent_thickness' => 4, 'accent_direction' => 90,
        ])->assertRedirect();
        $id = Site::where('name', 'VirdenMB')->value('id');
        $this->get("$base/$id/preview")->assertOk()->assertSee('VirdenMB');
        $this->get('https://virdenmb.ca/')->assertNotFound();
        $this->post("$base/$id/status", ['status' => 'active'])->assertSessionHasErrors('status');
        $this->assertDatabaseHas('sites', ['id' => $id, 'status' => 'draft']);
        $draft = Site::findOrFail($id);
        $old = $draft->readinessVersion();
        $this->post("$base/$id/readiness", ['dns' => '1', 'version' => $old])->assertRedirect()->assertSessionHasNoErrors();
        $this->post("$base/$id/readiness", ['tls' => '1', 'version' => $old])->assertSessionHasErrors('version');
        $this->post("$base/$id/status", ['status' => 'active', 'version' => $old])->assertSessionHasErrors('version');
        $this->assertDatabaseHas('sites', ['id' => $id, 'status' => 'draft']);
        $this->assertNotNull($draft->fresh()->domain->dns_confirmed_at);
        $this->assertNull($draft->fresh()->domain->tls_confirmed_at);
    }

    public function test_community_directory_filters_and_effective_configuration_are_read_only(): void
    {
        $base = 'https://canadamb.ca/admin/communities';
        $site = Site::where('code', 'brandon')->sole();
        $site->update(['overrides' => [
            'experience' => ['preset' => 'forest', 'clock' => 'hide', 'weather' => 'hide'],
            'manual_publication' => ['listings' => true],
        ]]);
        $site->domain->update(['dns_confirmed_at' => now(), 'tls_confirmed_at' => now()]);
        $this->actingAs(User::factory()->create())->get($base)->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'network_admin']));
        $this->get($base.'?q=brandonmb.ca&status=active&readiness=confirmed')->assertOk()
            ->assertSee('1 matching portal.')->assertSee('Prairie Forest')->assertSee('New Marketplace listings')
            ->assertSee('data-admin-portal="'.$site->id.'"', false);
        $this->get($base.'?q=brandonmb.ca&readiness=incomplete')->assertOk()->assertSee('No communities match these filters.');
        $this->get($base.'?q=brandon&status=draft')->assertOk()->assertSee('No communities match these filters.');
        $this->getJson($base.'?status=invalid')->assertUnprocessable();
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_posting_location_is_enforced_by_web_and_api(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $winnipeg = Location::where('slug', 'winnipeg')->value('id');
        $data = ['title' => 'Oak table', 'description' => 'A solid oak table.', 'price' => 100,
            'category' => 'furniture', 'condition' => 'good', 'location_id' => $winnipeg];
        $this->post('https://brandonmb.ca/marketplace', $data)->assertSessionHasErrors('location_id');
        $this->postJson('https://brandonmb.ca/api/v1/marketplace', $data)->assertUnprocessable()->assertJsonValidationErrors('location_id');
        $this->assertDatabaseCount('listings', 0);
    }

    public function test_site_module_override_depends_on_the_published_global_permission(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'super_admin']));
        $brandon = Site::where('code', 'brandon')->sole();
        $version = $brandon->configurationVersion();
        $input = [
            'name' => 'BrandonMB', 'description' => $brandon->description,
            'accent_type' => 'solid', 'accent_colors' => '#228855', 'accent_thickness' => 4, 'accent_direction' => 90,
            'modules' => ['businesses' => 'hide'], 'version' => $version,
        ];
        $url = "https://canadamb.ca/admin/communities/$brandon->id";
        $this->put($url, $input)->assertSessionHasNoErrors()->assertRedirect();
        $this->put($url, array_replace($input, ['name' => 'Stale Brandon name']))->assertSessionHasErrors('version');
        $this->assertSame('BrandonMB', $brandon->fresh()->name);
        $this->get('https://brandonmb.ca/businesses')->assertNotFound();
        $this->get('https://canadamb.ca/businesses')->assertOk();
        $this->get('https://brandonmb.ca/')->assertSee('#228855');
        $this->get('https://canadamb.ca/')->assertSee('#E85D75')->assertDontSee('#228855');

        $this->post('https://canadamb.ca/admin/settings', [
            'modules' => ['businesses' => 1, 'marketplace' => 1, 'news' => 1],
        ])->assertRedirect();
        $this->get('https://brandonmb.ca/businesses')->assertNotFound();
        $this->post('https://canadamb.ca/admin/settings/publish')->assertRedirect();
        $this->get('https://brandonmb.ca/businesses')->assertOk();
    }
}
