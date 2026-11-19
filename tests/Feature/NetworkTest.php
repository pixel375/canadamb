<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NetworkTest extends TestCase
{
    use RefreshDatabase;

    protected $seeder = DatabaseSeeder::class;

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

    public function test_administration_is_provincial_and_role_protected(): void
    {
        $this->get('https://canadamb.ca/admin')->assertRedirect();
        $this->actingAs(User::factory()->create())->get('https://canadamb.ca/admin')->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'super_admin']))
            ->get('https://brandonmb.ca/admin')->assertNotFound();
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
        $this->put("https://canadamb.ca/admin/communities/$brandon->id", [
            'name' => 'BrandonMB', 'description' => $brandon->description,
            'accent_type' => 'solid', 'accent_colors' => '#228855', 'accent_thickness' => 4, 'accent_direction' => 90,
            'modules' => ['businesses' => 'hide'],
        ])->assertSessionHasNoErrors()->assertRedirect();
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
