<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Location;
use App\Models\Site;
use App\Models\User;
use App\Support\CommercialCatalog;
use App\Support\NetworkSettings;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommercialPlanningTest extends TestCase
{
    use RefreshDatabase;

    protected $seeder = DatabaseSeeder::class;

    public function test_rate_card_is_private_versioned_and_never_activates_advertising(): void
    {
        $url = 'https://canadamb.ca/admin/commercial';
        $prices = ['business-pro' => '29.95', 'directory-sponsored' => null, 'home-banner' => '10.00', 'marketplace-banner' => '0'];
        $this->get($url)->assertRedirect();
        $this->actingAs(User::factory()->create(['role' => 'network_admin']))->get($url)->assertForbidden();
        $this->post($url, ['prices' => $prices])->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'super_admin']));
        $this->get('https://brandonmb.ca/admin/commercial')->assertNotFound();
        $this->get($url)->assertOk()->assertSee('Planning only')->assertSee('Not set');
        $this->post($url, ['prices' => array_replace($prices, ['unknown' => 1])])->assertSessionHasErrors('prices');
        $this->post($url, ['prices' => array_replace($prices, ['business-pro' => '-1'])])->assertSessionHasErrors('prices.business-pro');
        $this->post($url, ['prices' => $prices])->assertRedirect()->assertSessionHasNoErrors();
        $settings = app(NetworkSettings::class);
        $state = $settings->state();
        $draft = CommercialCatalog::fromSettings($settings->revision($state->draft_id));
        $this->assertSame(2995, $draft['business-pro']['price_cents']);
        $this->assertNull($draft['directory-sponsored']['price_cents']);
        $this->assertSame(0, $draft['marketplace-banner']['price_cents']);
        $this->assertSame(3000, CommercialCatalog::estimate($draft, 'home-banner', 3));
        $this->assertNull(CommercialCatalog::estimate($draft, 'directory-sponsored', 3));
        $this->assertNull(CommercialCatalog::estimate($draft, 'business-pro', 3));
        $this->assertNull(CommercialCatalog::estimate($draft, 'home-banner', 13));
        $this->assertNull(CommercialCatalog::fromSettings($settings->revision($state->published_id))['business-pro']['price_cents']);
        $business = Business::create(['name' => 'Example campaign shop', 'slug' => 'campaign-shop', 'category' => 'retail', 'status' => 'active']);
        $business->locations()->create(['location_id' => Location::where('slug', 'elkhorn')->sole()->id, 'address' => '1 Test Road']);
        $portal = Site::where('code', 'brandon')->sole();
        $this->get($url.'?business='.$business->id.'&portal='.$portal->id.'&placement=home-banner&weeks=3')
            ->assertOk()->assertSee('CAD $30.00')->assertSee('no physical branch in the selected community');
        $this->getJson($url.'?placement=business-pro')->assertUnprocessable();
        $this->post('https://canadamb.ca/admin/settings/publish')->assertRedirect();
        $this->get($url)->assertOk()->assertSee('CAD $29.95');
        foreach (['canadamb.ca', 'brandonmb.ca'] as $host) {
            $this->get("https://$host/")->assertOk()->assertDontSee('Sponsored · preview only')->assertDontSee('CAD $29.95');
            $this->get("https://$host/businesses")->assertOk()->assertDontSee('Sponsored · preview only');
        }
    }
}
