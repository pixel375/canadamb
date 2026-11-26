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
        $this->actingAs($admin)->get(self::MAIN.'/admin/moderation?status=removed&location='.$item->location_id)->assertOk()
            ->assertSee('Oak dining table')->assertSee('Historical record')->assertDontSee('action="/admin/moderation/'.$item->id.'"', false);
        $this->get(self::MAIN.'/admin/moderation?status=removed&location='.Location::where('slug', 'elkhorn')->sole()->id)->assertOk()->assertDontSee('Oak dining table');
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

    public function test_category_detail_filters_match_only_public_scoped_listings_and_validate_ranges(): void
    {
        $seller = User::factory()->create();
        $admin = User::factory()->create(['role' => 'network_admin']);
        $brandon = Location::where('slug', 'brandon')->sole();
        $rural = Location::where('slug', 'elkhorn')->sole();
        foreach ([
            [$brandon, 'Matching car', '2020', '45000', 'active'],
            [$brandon, 'High mileage car', 2020, 200000, 'active'],
            [$brandon, 'Old car', 2005, 45000, 'active'],
            [$brandon, 'Private car', 2020, 20000, 'pending'],
            [$rural, 'Rural car', 2021, 30000, 'active'],
        ] as [$place, $title, $year, $mileage, $status]) {
            $item = Listing::create(['user_id' => $seller->id, 'location_id' => $place->id, 'title' => $title,
                'description' => 'A vehicle fixture.', 'category' => 'cars', 'condition' => 'good', 'price_cents' => 100000,
                'status' => $status, 'attributes' => ['make' => 'Honda', 'model' => 'Civic', 'year' => $year, 'mileage' => $mileage]]);
            $item->forceFill(['published_at' => now(), 'expires_at' => now()->addWeek()])->save();
        }
        $this->getJson(self::CITY.'/api/v1/marketplace?category=cars&make=HONDA&year_min=2018&mileage_max=50000')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Matching car');
        $this->get(self::MAIN.'/marketplace?category=cars&make=Honda&year_min=2018&mileage_max=50000')
            ->assertOk()->assertSee('Matching car')->assertSee('Rural car')->assertDontSee('Private car')->assertDontSee('High mileage car')
            ->assertSee('2020 · Honda · Civic · 45,000 km')->assertSee('href="/marketplace/new?category=cars"', false);
        $this->actingAs($seller)->get(self::MAIN.'/marketplace/new?category=cars&location=brandon')->assertOk()
            ->assertSee('value="cars" selected', false)->assertSee('name="details[mileage]"', false);
        $this->getJson(self::CITY.'/api/v1/marketplace?category=furniture&year_min=2018')->assertUnprocessable()->assertJsonValidationErrors('year_min');
        $this->getJson(self::CITY.'/api/v1/marketplace?category=cars&year_min=2020&year_max=2010')->assertUnprocessable()->assertJsonValidationErrors('year_max');
        $this->getJson(self::CITY.'/api/v1/marketplace?category=cars&mileage_max=-1')->assertUnprocessable();
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

    public function test_seller_filters_scope_expiry_reference_category_and_order_to_owned_listings(): void
    {
        $seller = User::factory()->create();
        $other = User::factory()->create();
        $city = Location::where('slug', 'brandon')->sole();
        $rural = Location::where('slug', 'elkhorn')->sole();
        $items = collect();
        foreach ([[$seller, $city, 'Soon desk', 'furniture', 1000, now()->addDay()],
            [$seller, $city, 'Later desk', 'furniture', 2000, now()->addDays(20)],
            [$seller, $rural, 'Ended computer', 'computers', 3000, now()->subDay()],
            [$other, $city, 'Other private desk', 'furniture', 500, now()->addDay()]] as [$owner, $place, $title, $category, $price, $expiry]) {
            $item = Listing::create(['user_id' => $owner->id, 'location_id' => $place->id, 'title' => $title,
                'description' => 'Listing fixture', 'category' => $category, 'condition' => 'good', 'price_cents' => $price, 'status' => 'active']);
            $item->forceFill(['expires_at' => $expiry])->save();
            $items->push($item);
        }
        $base = self::MAIN.'/account/listings';
        $this->actingAs($seller)->get($base.'?location='.$city->id.'&category=furniture&sort=price-high')->assertOk()
            ->assertSee('2 listings found')->assertSeeInOrder(['Later desk', 'Soon desk'])->assertDontSee('Other private desk')->assertDontSee('Ended computer');
        $this->get($base.'?expiry=soon')->assertOk()->assertSee('1 listing found')->assertSee('Soon desk')->assertDontSee('Later desk');
        $this->get($base.'?expiry=ended&category=computers')->assertOk()->assertSee('Ended computer')->assertDontSee('Soon desk');
        $this->get($base.'?reference='.$items[3]->id)->assertOk()->assertSee('0 listings found')->assertDontSee('Other private desk');
        $this->getJson(self::CITY.'/account/listings?location='.$rural->id)->assertUnprocessable();
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

    public function test_selected_moderation_is_independent_atomic_and_versioned(): void
    {
        $seller = User::factory()->create();
        $reviewer = User::factory()->create(['role' => 'network_admin']);
        $location = Location::where('slug', 'brandon')->sole()->id;
        $items = collect();
        foreach ([$seller, $seller, $reviewer] as $owner) {
            $items->push(Listing::create(['user_id' => $owner->id, 'location_id' => $location,
                'title' => 'Selected review item', 'description' => 'Local furniture', 'category' => 'furniture',
                'condition' => 'good', 'price_cents' => 1000, 'status' => 'pending'])->fresh());
        }
        $url = self::MAIN.'/admin/moderation/selected';
        $versions = $items->mapWithKeys(fn ($item) => [$item->id => $item->reviewVersion()])->all();
        $data = ['status' => 'active', 'listings' => [$items[0]->id, $items[1]->id], 'versions' => $versions, 'confirm_review' => '1'];
        $this->actingAs($seller)->post($url, $data)->assertForbidden();
        $this->actingAs($reviewer)->get(self::MAIN.'/admin/moderation')->assertOk()->assertSee('Review selected listings');
        $this->post($url, array_replace($data, ['listings' => [$items[0]->id, $items[2]->id]]))->assertForbidden();
        $this->post($url, array_replace($data, ['versions' => array_replace($versions, [$items[1]->id => str_repeat('0', 64)])]))->assertSessionHasErrors('listings');
        $this->post($url, array_replace($data, ['status' => 'rejected']))->assertSessionHasErrors('reason');
        $this->assertSame('pending', $items[0]->fresh()->status);
        $this->assertDatabaseCount('listing_status_history', 0);
        $this->post($url, $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('active', $items[0]->fresh()->status);
        $this->assertSame('active', $items[1]->fresh()->status);
        $this->assertSame('pending', $items[2]->fresh()->status);
        $this->assertNotNull($items[0]->fresh()->expires_at);
        $this->assertDatabaseCount('listing_status_history', 2);
        $this->assertDatabaseHas('audit_logs', ['action' => 'listing.active', 'actor_id' => $reviewer->id]);
        $versions = $items->mapWithKeys(fn ($item) => [$item->id => $item->fresh()->reviewVersion()])->all();
        $this->post($url, array_replace($data, ['status' => 'removed', 'reason' => 'Duplicate public advertisements', 'versions' => $versions]))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('removed', $items[0]->fresh()->status);
        $this->assertSame('removed', $items[1]->fresh()->status);
        $this->assertDatabaseCount('listing_status_history', 4);
    }

    public function test_member_inventory_is_paginated_searchable_and_never_includes_private_or_other_portal_listings(): void
    {
        $seller = User::factory()->create();
        $other = User::factory()->create();
        $brandon = Location::where('slug', 'brandon')->sole()->id;
        $rural = Location::where('slug', 'elkhorn')->sole()->id;
        foreach (range(1, 13) as $index) {
            $listing = Listing::create(['user_id' => $seller->id, 'location_id' => $brandon, 'title' => 'Local item '.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
                'description' => 'A public item for sale.', 'category' => 'furniture', 'condition' => 'good', 'price_cents' => $index * 100]);
            $listing->forceFill(['status' => 'active', 'expires_at' => now()->addDay(), 'published_at' => now()])->save();
        }
        foreach ([[$seller, $brandon, 'Hidden pending listing', 'pending'], [$seller, $rural, 'Rural public listing', 'active'], [$other, $brandon, 'Different seller listing', 'active']] as [$owner, $place, $title, $status]) {
            $listing = Listing::create(['user_id' => $owner->id, 'location_id' => $place, 'title' => $title, 'description' => 'A fixture listing.',
                'category' => 'furniture', 'condition' => 'good', 'price_cents' => 100]);
            $listing->forceFill(['status' => $status, 'expires_at' => now()->addDay(), 'published_at' => now()])->save();
        }
        $url = self::CITY.'/users/'.$seller->id;
        $this->get($url.'?sort=price-low')->assertOk()->assertSee('13 active listings')->assertSee('Local item 01')->assertDontSee('Local item 13')
            ->assertDontSee('Hidden pending listing')->assertDontSee('Rural public listing')->assertDontSee('Different seller listing')->assertDontSee($seller->email);
        $this->get($url.'?sort=price-low&listing_page=2')->assertOk()->assertSee('Local item 13')->assertDontSee('Local item 01');
        $this->get($url.'?q=Local%2003')->assertOk()->assertSee('Local item 03')->assertDontSee('Local item 04');
        $this->get(self::MAIN.'/users/'.$seller->id.'?q=Rural')->assertOk()->assertSee('Rural public listing');
    }

    private function listing(string $title): array
    {
        return ['title' => $title, 'description' => 'Includes the table and chairs.', 'category' => 'furniture',
            'condition' => 'good', 'price' => '200.25'];
    }

    public function test_expired_listing_renewal_after_review_is_seven_days_and_edits_do_not_extend_active_period(): void
    {
        $this->freezeSecond();
        $owner = User::factory()->create();
        $admin = User::factory()->create(['role' => 'network_admin']);
        $rural = Location::where('slug', 'elkhorn')->sole();
        $this->actingAs($owner)->postJson(self::MAIN.'/api/v1/marketplace', $this->listing('Renewal fixture') + ['location_id' => $rural->id])->assertCreated();
        $item = Listing::sole();
        $moderate = self::MAIN.'/admin/moderation/'.$item->id;
        $status = self::MAIN.'/account/listings/'.$item->id.'/status';
        $this->actingAs($admin)->post($moderate, ['status' => 'active', 'version' => $item->fresh()->reviewVersion()])->assertRedirect();
        $this->assertSame(now()->addDays(30)->timestamp, $item->fresh()->expires_at->timestamp);
        $this->travel(31)->days();
        $this->artisan('marketplace:expire')->assertSuccessful();
        $this->actingAs(User::factory()->create())->post($status, ['status' => 'pending', 'version' => $item->fresh()->reviewVersion()])->assertNotFound();
        $this->actingAs($owner)->get(self::MAIN.'/account/listings?status=expired')->assertOk()->assertSee('Renew for 7 days');
        $version = $item->fresh()->reviewVersion();
        $this->post($status, ['status' => 'pending', 'version' => $version])->assertRedirect();
        $this->assertSame('pending', $item->fresh()->status);
        $this->post($status, ['status' => 'pending', 'version' => $version])->assertConflict();
        $this->actingAs($admin)->post($moderate, ['status' => 'active', 'version' => $item->fresh()->reviewVersion()])->assertRedirect();
        $expires = $item->fresh()->expires_at->timestamp;
        $this->assertSame(now()->addDays(7)->timestamp, $expires);
        $this->travel(1)->days();
        $item->refresh()->forceFill(['status' => 'pending'])->save();
        $this->post($moderate, ['status' => 'active', 'version' => $item->fresh()->reviewVersion()])->assertRedirect();
        $this->assertSame($expires, $item->fresh()->expires_at->timestamp);
    }

}
