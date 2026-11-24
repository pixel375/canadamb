<?php

namespace Tests\Feature;

use App\Models\Listing;
use App\Models\Location;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketplaceTest extends TestCase
{
    use RefreshDatabase;

    protected $seeder = DatabaseSeeder::class;

    private const MAIN = 'https://canadamb.ca';

    private const CITY = 'https://brandonmb.ca';

    public function test_listing_has_one_record_and_one_canonical_domain_across_both_feeds(): void
    {
        $seller = User::factory()->create();
        $admin = User::factory()->create(['role' => 'network_admin']);
        $this->actingAs($seller)->get(self::CITY.'/marketplace/new')->assertOk()->assertSee('readonly', false);
        $this->post(self::CITY.'/marketplace', $this->listing('Oak table'))->assertSessionHasNoErrors()->assertRedirect('/account/listings');
        $item = Listing::sole();
        $this->assertSame('brandon', $item->location->slug);
        $this->get(self::CITY.'/marketplace')->assertDontSee('Oak table');
        $this->actingAs($admin)->post(self::MAIN."/admin/moderation/$item->id", ['status' => 'active'])->assertRedirect();
        $this->get(self::CITY.'/marketplace?q=Oak&category=furniture')->assertOk()->assertSee('Oak table');
        $this->get(self::MAIN.'/marketplace?view=all')->assertOk()->assertSee('Oak table');
        $this->get(self::CITY."/marketplace/$item->id")->assertOk()->assertSee('Oak table');
        $this->get(self::MAIN."/marketplace/$item->id")
            ->assertOk()->assertSee('Oak table')
            ->assertSee('<link rel="canonical" href="'.self::CITY."/marketplace/$item->id".'">', false)
            ->assertSee('href="/marketplace?category=furniture"', false)
            ->assertSee('Member since');
        $this->get(self::CITY."/marketplace/$item->id")
            ->assertOk()->assertSee('<link rel="canonical" href="'.self::CITY."/marketplace/$item->id".'">', false);
        $this->get(self::CITY.'/sitemap.xml')->assertSee(self::CITY."/marketplace/$item->id");
        $this->get(self::MAIN.'/sitemap.xml')->assertDontSee("/marketplace/$item->id");

        $this->actingAs(User::factory()->create())->put(self::CITY."/account/listings/$item->id", $this->listing('Changed by outsider'))->assertNotFound();
        $this->actingAs($seller)->put(self::CITY."/account/listings/$item->id", $this->listing('Oak dining table'))->assertRedirect();
        $this->get(self::MAIN.'/marketplace')->assertDontSee('Oak table');
        $this->actingAs($admin)->post(self::MAIN."/admin/moderation/$item->id", ['status' => 'active'])->assertRedirect();
        $this->get(self::MAIN.'/marketplace?view=all')->assertSee('Oak dining table');
        $this->get(self::CITY.'/marketplace?view=all')->assertSee('Oak dining table');

        $this->actingAs($seller)->post(self::CITY."/account/listings/$item->id/status", ['status' => 'removed'])->assertRedirect();
        $this->get(self::CITY.'/marketplace')->assertDontSee('Oak dining table');
        $this->get(self::MAIN.'/marketplace')->assertDontSee('Oak dining table');
        $this->get(self::CITY."/marketplace/$item->id")->assertNotFound();
        $this->assertDatabaseCount('listings', 1);
        $this->assertDatabaseHas('listing_status_history', ['listing_id' => $item->id, 'status' => 'removed']);
    }

    public function test_manitoba_feed_includes_rural_items_that_brandon_cannot_see(): void
    {
        $rural = Location::where('slug', 'elkhorn')->sole();
        $this->actingAs(User::factory()->create())->postJson(self::MAIN.'/api/v1/marketplace', $this->listing('Elkhorn patio set') + ['location_id' => $rural->id])
            ->assertCreated()->assertJsonPath('data.location', 'Elkhorn');
        $item = Listing::sole();
        $this->actingAs(User::factory()->create(['role' => 'network_admin']))
            ->post(self::MAIN."/admin/moderation/$item->id", ['status' => 'active'])->assertRedirect();
        $this->get(self::MAIN.'/marketplace?view=all&location=elkhorn')->assertSee('Elkhorn patio set');
        $this->get(self::MAIN.'/?location=elkhorn')->assertSee('Elkhorn patio set');
        $this->get(self::CITY.'/marketplace?location=elkhorn')->assertDontSee('Elkhorn patio set');
        $this->getJson(self::CITY.'/api/v1/marketplace?location=elkhorn')->assertJsonCount(0, 'data');
        $this->get(self::CITY."/marketplace/$item->id")->assertNotFound();
        $this->get(self::MAIN."/marketplace/$item->id")->assertOk();
        $this->get(self::MAIN.'/sitemap.xml')->assertSee(self::MAIN."/marketplace/$item->id");

        $this->travel(31)->days();
        $this->get(self::MAIN."/marketplace/$item->id")->assertNotFound();
        $this->artisan('marketplace:expire')->assertSuccessful();
        $this->assertDatabaseHas('listings', ['id' => $item->id, 'status' => 'expired']);
    }

    public function test_keyword_search_combines_public_fields_and_preserves_visibility(): void
    {
        $seller = User::factory()->create();
        $admin = User::factory()->create(['role' => 'network_admin']);
        $rural = Location::where('slug', 'elkhorn')->sole();

        $this->actingAs($seller)->postJson(self::MAIN.'/api/v1/marketplace',
            $this->listing('Oak dining set') + ['location_id' => $rural->id])->assertCreated();
        $item = Listing::sole();
        $this->getJson(self::MAIN.'/api/v1/marketplace?q=chairs%20OAK')->assertJsonCount(0, 'data');

        $this->actingAs($admin)->post(self::MAIN."/admin/moderation/$item->id", ['status' => 'active'])->assertRedirect();
        $this->getJson(self::MAIN.'/api/v1/marketplace?q=chairs%20OAK')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson(self::MAIN.'/api/v1/marketplace?q=oak%20missingword')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(self::MAIN.'/api/v1/marketplace?q=%25')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(self::MAIN.'/api/v1/marketplace?q=_')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(self::MAIN.'/api/v1/marketplace?q=chairs%20OAK&location=brandon')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(self::CITY.'/api/v1/marketplace?q=chairs%20OAK')->assertOk()->assertJsonCount(0, 'data');
    }

    private function listing(string $title): array
    {
        return ['title' => $title, 'description' => 'Includes the table and chairs.', 'category' => 'furniture',
            'condition' => 'good', 'price' => '200.25'];
    }
}
