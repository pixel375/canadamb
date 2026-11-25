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
        $this->actingAs($admin)->post(self::MAIN."/admin/moderation/$item->id", ['status' => 'active', 'version' => $item->fresh()->reviewVersion()])->assertRedirect();
        $this->get(self::CITY.'/marketplace?q=Oak&category=furniture')->assertOk()->assertSee('Oak table');
        $this->get(self::MAIN.'/marketplace?view=all')->assertOk()->assertSee('Oak table');
        $this->get(self::CITY."/marketplace/$item->id")->assertOk()->assertSee('Oak table');
        $this->get(self::MAIN."/marketplace/$item->id")
            ->assertOk()->assertSee('Oak table')
            ->assertSee('<link rel="canonical" href="'.self::CITY."/marketplace/$item->id".'">', false)
            ->assertSee('href="/marketplace?category=furniture&amp;location=brandon"', false)
            ->assertSee('Member since');
        $this->get(self::CITY."/marketplace/$item->id")
            ->assertOk()->assertSee('<link rel="canonical" href="'.self::CITY."/marketplace/$item->id".'">', false)
            ->assertSee('href="/marketplace?category=furniture"', false);
        $this->get(self::CITY.'/sitemap.xml')->assertSee(self::CITY."/marketplace/$item->id");
        $this->get(self::MAIN.'/sitemap.xml')->assertDontSee("/marketplace/$item->id");

        $this->actingAs(User::factory()->create())->put(self::CITY."/account/listings/$item->id", $this->listing('Changed by outsider'))->assertNotFound();
        $oldReview = $item->fresh()->reviewVersion();
        $this->actingAs($seller)->put(self::CITY."/account/listings/$item->id", $this->listing('Oak dining table'))->assertRedirect();
        $this->actingAs($admin)->post(self::MAIN."/admin/moderation/$item->id", ['status' => 'active', 'version' => $oldReview])->assertConflict();
        $this->actingAs($seller);
        $this->get(self::MAIN.'/marketplace')->assertDontSee('Oak table');
        $this->actingAs($admin)->post(self::MAIN."/admin/moderation/$item->id", ['status' => 'active', 'version' => $item->fresh()->reviewVersion()])->assertRedirect();
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
            ->post(self::MAIN."/admin/moderation/$item->id", ['status' => 'active', 'version' => $item->fresh()->reviewVersion()])->assertRedirect();
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

        $this->actingAs($admin)->post(self::MAIN."/admin/moderation/$item->id", ['status' => 'active', 'version' => $item->fresh()->reviewVersion()])->assertRedirect();
        $this->getJson(self::MAIN.'/api/v1/marketplace?q=chairs%20OAK')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson(self::MAIN.'/api/v1/marketplace?q=oak%20missingword')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(self::MAIN.'/api/v1/marketplace?q=%25')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(self::MAIN.'/api/v1/marketplace?q=_')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(self::MAIN.'/api/v1/marketplace?q=chairs%20OAK&location=brandon')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(self::CITY.'/api/v1/marketplace?q=chairs%20OAK')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_seller_dashboard_filters_own_listings_with_portal_scope(): void
    {
        $seller = User::factory()->create();
        $other = User::factory()->create();
        $brandon = Location::where('slug', 'brandon')->sole();
        $elkhorn = Location::where('slug', 'elkhorn')->sole();
        foreach ([
            [$seller, $brandon, 'Pending oak desk', 'pending'],
            [$seller, $brandon, 'Sold patio set', 'sold'],
            [$seller, $elkhorn, 'Rural bookshelf', 'expired'],
            [$other, $brandon, 'Private outsider inventory', 'pending'],
        ] as [$user, $place, $title, $status]) {
            Listing::create([
                'user_id' => $user->id, 'location_id' => $place->id,
                'title' => $title, 'description' => 'Local furniture', 'category' => 'furniture',
                'condition' => 'good', 'price_cents' => 2500, 'status' => $status,
            ]);
        }

        $this->actingAs($seller)->get(self::MAIN.'/account/listings')->assertOk()
            ->assertSee('3 listings found')->assertSee('Pending oak desk')
            ->assertSee('Sold patio set')->assertSee('Rural bookshelf')->assertDontSee('Private outsider inventory');
        $this->get(self::CITY.'/account/listings')->assertOk()->assertSee('2 listings found')->assertDontSee('Rural bookshelf');
        $this->get(self::MAIN.'/account/listings?status=pending&q=oak')->assertOk()
            ->assertSee('1 listing found')->assertSee('Pending oak desk')->assertDontSee('Sold patio set');
        $this->get(self::MAIN.'/account/listings?status=sold&q=oak')->assertOk()->assertSee('No listings match these filters.');
        $sold = Listing::where('title', 'Sold patio set')->sole();
        $rural = Listing::where('title', 'Rural bookshelf')->sole();
        $this->get(self::CITY.'/account/listings/'.$sold->id.'/duplicate')->assertOk()->assertSee('Copied text and item details only.')->assertSee('Sold patio set');
        $this->assertDatabaseCount('listings', 4);
        $this->get(self::CITY.'/account/listings/'.$sold->id.'/history')->assertOk()->assertSee('Status history');
        $this->get(self::CITY.'/account/listings/'.$rural->id.'/duplicate')->assertNotFound();
        $this->actingAs($other)->get(self::MAIN.'/account/listings/'.$sold->id.'/history')->assertNotFound();
        $this->actingAs($seller);
        $this->getJson(self::MAIN.'/account/listings?status=removed')->assertUnprocessable();
    }

    public function test_versioned_listing_edits_do_not_unpublish_unchanged_content(): void
    {
        $seller = User::factory()->create();
        $admin = User::factory()->create(['role' => 'network_admin']);
        $data = $this->listing('Oak desk');
        $this->actingAs($seller)->post(self::CITY.'/marketplace', $data)->assertRedirect();
        $item = Listing::sole();
        $path = self::CITY.'/account/listings/'.$item->id;
        $this->actingAs($admin)->post(self::MAIN.'/admin/moderation/'.$item->id, ['status' => 'active', 'version' => $item->fresh()->reviewVersion()])->assertRedirect();
        $version = $item->fresh()->reviewVersion();
        $this->actingAs($seller)->put($path, $data + ['version' => $version])->assertRedirect();
        $this->assertSame('active', $item->fresh()->status);
        $this->put($path, array_replace($data, ['title' => 'Revised desk', 'version' => $version]))->assertRedirect();
        $this->put($path, $data + ['version' => $version])->assertConflict();
        $this->post($path.'/status', ['status' => 'removed', 'version' => $version])->assertConflict();
        $this->assertSame('Revised desk', $item->fresh()->title);
        $this->assertSame('pending', $item->fresh()->status);
    }

    public function test_bulk_listing_status_changes_are_atomic_versioned_and_portal_scoped(): void
    {
        $seller = User::factory()->create();
        $other = User::factory()->create();
        $items = collect();
        foreach ([[$seller, 'brandon'], [$seller, 'brandon'], [$seller, 'elkhorn'], [$other, 'brandon']] as [$member, $place]) {
            $item = Listing::create(['user_id' => $member->id, 'location_id' => Location::where('slug', $place)->sole()->id,
                'title' => 'Batch listing', 'description' => 'Local furniture', 'category' => 'furniture',
                'condition' => 'good', 'price_cents' => 1000, 'status' => 'active']);
            $item->forceFill(['expires_at' => now()->addDay()])->save();
            $items->push($item->fresh());
        }
        $url = self::CITY.'/account/listings/batch-status';
        $versions = $items->mapWithKeys(fn ($item) => [$item->id => $item->reviewVersion()])->all();
        $input = ['status' => 'sold', 'listings' => [$items[0]->id, $items[1]->id], 'versions' => $versions, 'confirm_batch' => '1'];
        $this->actingAs($seller)->get(self::CITY.'/account/listings')->assertOk()->assertSee('Manage selected listings');
        $this->post($url, array_replace($input, ['listings' => [$items[0]->id, $items[2]->id]]))->assertNotFound();
        $this->post($url, array_replace($input, ['listings' => [$items[0]->id, $items[3]->id]]))->assertNotFound();
        $this->post($url, array_replace($input, ['versions' => array_replace($versions, [$items[1]->id => str_repeat('0', 64)])]))->assertSessionHasErrors('listings');
        $this->assertSame('active', $items[0]->fresh()->status);
        $this->post($url, $input)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('sold', $items[0]->fresh()->status);
        $this->assertSame('sold', $items[1]->fresh()->status);
        $this->assertSame('active', $items[2]->fresh()->status);
        $this->assertSame('active', $items[3]->fresh()->status);
        $this->assertDatabaseCount('listing_status_history', 2);
    }

    private function listing(string $title): array
    {
        return ['title' => $title, 'description' => 'Includes the table and chairs.', 'category' => 'furniture',
            'condition' => 'good', 'price' => '200.25'];
    }
}
