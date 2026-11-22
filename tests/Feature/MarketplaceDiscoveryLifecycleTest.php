<?php

namespace Tests\Feature;

use App\Models\Listing;
use App\Models\Location;
use App\Models\Site;
use App\Models\User;
use App\Support\Identity;
use App\Support\ListingPhotoOrder;
use App\Support\Marketplace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MarketplaceDiscoveryLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private const MAIN = 'https://canadamb.ca';

    private const CITY = 'https://brandonmb.ca';

    public function test_discovery_uses_literal_terms_zero_price_and_stable_price_sorting(): void
    {
        $seller = User::factory()->create();
        $free = $this->published($seller, 'Clearance 50% off', 0);
        $first = $this->published($seller, 'Furniture 50% off', 4000);
        $second = $this->published($seller, 'Lamp 50% off', 4000);
        $rural = $this->published($seller, 'Elkhorn 50% off', 2500, 'elkhorn');
        $this->published($seller, 'Furniture 500 off', 1000);
        $this->published($seller, 'Table <script>fixture</script>', 9000);

        $this->getJson(self::MAIN.'/api/v1/marketplace?q=50%25&location=brandon&min_price=0&max_price=40&sort=price_asc')
            ->assertOk()->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data.0.id', $free->id)->assertJsonPath('data.1.id', $second->id)->assertJsonPath('data.2.id', $first->id);
        $this->getJson(self::MAIN.'/api/v1/marketplace?q=50%25&min_price=1&sort=price_desc')
            ->assertOk()->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data.0.id', $second->id)->assertJsonPath('data.1.id', $first->id)->assertJsonPath('data.2.id', $rural->id);
        $this->getJson(self::CITY.'/api/v1/marketplace?location[]=elkhorn&max_price=0')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $free->id);
        $this->getJson(self::MAIN.'/api/v1/marketplace?q=0')->assertOk()->assertJsonPath('meta.total', 5);
        $this->get(self::CITY.'/marketplace')->assertOk()->assertSee('Table &lt;script&gt;fixture&lt;/script&gt;', false)
            ->assertDontSee('<script>fixture</script>', false);
    }

    public function test_discovery_rejects_invalid_filters_and_carries_only_applied_parameters_between_pages(): void
    {
        $this->getJson(self::MAIN.'/api/v1/marketplace?min_price=20&max_price=10&category[]=furniture&sort=unknown&location=missing')
            ->assertUnprocessable()->assertJsonValidationErrors(['max_price', 'category', 'sort', 'location']);
        $this->getJson(self::MAIN.'/api/v1/marketplace?min_price=-1&max_price=0.001&condition=unknown&q[]=table')
            ->assertUnprocessable()->assertJsonValidationErrors(['min_price', 'max_price', 'condition', 'q']);
        $seller = User::factory()->create();
        foreach (range(1, 19) as $number) {
            $this->published($seller, 'Table '.$number, $number * 100);
        }

        $this->get(self::MAIN.'/marketplace?q=Table&category=&min_price=0&sort=price_asc&unused=fixture')
            ->assertOk()->assertSee('2 active filters')->assertSee('Clear filters')->assertSee('min_price=0')
            ->assertDontSee('unused=fixture')->assertDontSee('category=&amp;', false);
        $this->get(self::MAIN.'/marketplace?category=&sort=newest')->assertOk()->assertDontSee('active filters')->assertDontSee('Clear filters');
    }

    public function test_owner_management_exposes_rejection_details_only_in_its_portal_scope_and_retains_hidden_history(): void
    {
        $seller = User::factory()->create();
        $admin = User::factory()->create(['role' => 'network_admin']);
        $item = $this->published($seller, 'Listing for review', 3000, 'elkhorn');
        $reason = 'Please clarify the posting condition.';
        $manage = '/account/listings/'.$item->id;

        $this->actingAs($admin)->post(self::MAIN.'/admin/moderation/'.$item->id, [
            'status' => 'rejected', 'reason' => $reason, 'version' => ListingPhotoOrder::version($item->fresh()),
        ])->assertRedirect();
        $this->actingAs(User::factory()->create())->get(self::MAIN.'/account/listings')->assertOk()->assertDontSee($reason);
        $this->actingAs($seller)->get(self::CITY.'/account/listings')->assertOk()->assertDontSee($reason);
        $this->get(self::MAIN.'/account/listings')->assertOk()->assertSee($reason)->assertSee('Rejected')->assertSee('Remove listing');
        $this->post(self::CITY.$manage.'/status', ['status' => 'removed'])->assertNotFound();
        $this->post(self::MAIN.$manage.'/status', ['status' => 'removed'])->assertRedirect('/account/listings');
        $this->get(self::MAIN.'/account/listings')->assertOk()->assertSee('Listing for review')->assertSee('Removed')->assertDontSee($reason)
            ->assertDontSee($manage.'/edit');
        $this->get(self::MAIN.'/marketplace/'.$item->id)->assertNotFound();
        $this->assertDatabaseHas('listing_status_history', ['listing_id' => $item->id, 'status' => 'removed']);
        $this->assertDatabaseMissing('notifications', ['resource_id' => $item->id, 'event' => 'listing.removed']);
    }

    public function test_sold_and_elapsed_items_require_a_fresh_review_when_relisted(): void
    {
        $this->travelTo(Carbon::parse('2026-07-01T18:00:00Z'));
        $seller = User::factory()->create();
        $sold = $this->published($seller, 'Sold dresser', 8000);
        $elapsed = $this->published($seller, 'Elapsed chair', 1100);
        $root = self::CITY.'/account/listings';

        $this->actingAs($seller)->get($root)->assertOk()->assertSee('Jul 31, 2026 at 1:00 pm CDT');
        $this->post($root.'/'.$sold->id.'/status', ['status' => 'sold'])->assertRedirect();
        $this->post($root.'/'.$sold->id.'/status', ['status' => 'pending'])->assertRedirect();
        $this->get(self::CITY.'/marketplace/'.$sold->id)->assertNotFound();
        $this->assertDatabaseHas('listing_status_history', ['listing_id' => $sold->id, 'previous_status' => 'sold', 'status' => 'pending']);

        $this->travel(29)->days();
        $this->artisan('marketplace:expire')->assertSuccessful();
        $this->assertSame('active', $elapsed->fresh()->status);

        $this->travel(1)->days();
        $this->get($root)->assertOk()->assertSee('Relist for review')->assertSee('Expired');
        $this->post($root.'/'.$elapsed->id.'/status', ['status' => 'sold'])->assertConflict();
        $this->post($root.'/'.$elapsed->id.'/status', ['status' => 'pending'])->assertRedirect();
        $this->artisan('marketplace:expire')->assertSuccessful();
        $this->artisan('marketplace:expire')->assertSuccessful();
        $this->assertSame(1, DB::table('listing_status_history')->where(['listing_id' => $elapsed->id, 'status' => 'expired'])->count());
        $this->assertSame(1, DB::table('notifications')->where(['resource_id' => $elapsed->id, 'event' => 'listing.expired'])->count());
        $this->assertSame('pending', $elapsed->fresh()->status);
        $this->assertSame('pending', $sold->fresh()->status);
        $this->get(self::CITY.'/marketplace/'.$elapsed->id)->assertNotFound();

        $this->actingAs(User::factory()->create(['role' => 'network_admin']))
            ->post(self::MAIN.'/admin/moderation/'.$elapsed->id, ['status' => 'active', 'version' => ListingPhotoOrder::version($elapsed->fresh())])->assertRedirect();
        $this->get(self::CITY.'/marketplace/'.$elapsed->id)->assertOk();
        $this->assertTrue($elapsed->fresh()->expires_at->isFuture());
    }

    public function test_saved_state_and_single_report_are_private_and_follow_current_portal_scope(): void
    {
        $seller = User::factory()->create();
        $buyer = User::factory()->create();
        $item = $this->published($seller, 'Saved Elkhorn lamp', 2500, 'elkhorn');
        $path = '/marketplace/'.$item->id;
        $reason = 'The lamp appears damaged in a photo.';

        $this->actingAs($buyer)->post(self::CITY.$path.'/save')->assertNotFound();
        $this->post(self::MAIN.$path.'/save', ['return_to' => 'listing'])->assertRedirect($path);
        $this->get(self::MAIN.$path)->assertOk()->assertSee('Unsave listing')->assertDontSee($seller->email);
        $this->delete(self::CITY.$path.'/save')->assertNotFound();
        $this->get(self::MAIN.'/account/saved')->assertOk()->assertSee($item->title);
        $this->get(self::CITY.'/account/saved')->assertOk()->assertDontSee($item->title);
        $this->post(self::MAIN.$path.'/report', ['reason' => $reason])->assertRedirect($path)->assertSessionHas('status', 'Your report was submitted for review.');
        $this->post(self::MAIN.$path.'/report', ['reason' => 'Another report should keep the first reason.'])
            ->assertRedirect($path)->assertSessionHas('status', 'You have already reported this listing.');
        $this->get(self::MAIN.$path)->assertOk()->assertSee('You have already reported this listing.')->assertDontSee($reason);
        $this->assertDatabaseCount('listing_reports', 1);
        $this->assertDatabaseHas('listing_reports', ['listing_id' => $item->id, 'reason' => $reason]);
        $this->actingAs($seller)->post(self::MAIN.$path.'/report', ['reason' => $reason])->assertForbidden();
        $this->post(self::MAIN.'/account/listings/'.$item->id.'/status', ['status' => 'sold'])->assertRedirect();
        $this->actingAs(User::factory()->create())->delete(self::MAIN.$path.'/save')->assertNotFound();
        $this->actingAs($buyer)->get(self::MAIN.'/account/saved')->assertOk()->assertDontSee($item->title);
        $this->assertDatabaseHas('listing_favourites', ['user_id' => $buyer->id, 'listing_id' => $item->id]);
        $this->delete(self::MAIN.$path.'/save', ['return_to' => 'listing'])->assertRedirect('/account/saved');
        $this->assertDatabaseMissing('listing_favourites', ['user_id' => $buyer->id, 'listing_id' => $item->id]);
    }

    public function test_city_sign_in_remembers_the_listing_contact_action_and_rechecks_visibility(): void
    {
        $seller = User::factory()->create();
        $item = $this->published($seller, 'Contact desk', 5000);
        $path = '/marketplace/'.$item->id;
        Identity::provision(Site::with('domain')->where('code', 'brandon')->sole());

        $this->get(self::CITY.$path)->assertOk()->assertSee('href="'.$path.'/contact"', false);
        $this->get(self::CITY.$path.'/contact')->assertRedirect('/auth/start')->assertSessionHas('url.intended', self::CITY.$path.'/contact');
        $authorize = $this->get(self::CITY.'/auth/start')->assertRedirect()->headers->get('Location');
        parse_str(parse_url($authorize, PHP_URL_QUERY), $parameters);
        $this->assertSame($path.'/contact', session('community_oauth.'.$parameters['state'].'.path'));
        $this->actingAs(User::factory()->unverified()->create())->get(self::CITY.$path.'/contact')->assertRedirect(self::MAIN.'/email/verify');
        $this->actingAs(User::factory()->create())->get(self::CITY.$path.'/contact?return=https://example.test')->assertRedirect($path.'#message-seller');
        $this->actingAs($seller)->post(self::CITY.'/account/listings/'.$item->id.'/status', ['status' => 'sold'])->assertRedirect();
        $this->actingAs(User::factory()->create())->get(self::CITY.$path.'/contact')->assertNotFound();
    }

    private function published(User $seller, string $title, int $cents, string $location = 'brandon'): Listing
    {
        $item = Listing::create([
            'user_id' => $seller->id, 'location_id' => Location::where('slug', $location)->sole()->id,
            'category' => 'furniture', 'title' => $title, 'description' => 'Available for pickup this weekend.',
            'condition' => 'good', 'price_cents' => $cents,
        ]);
        app(Marketplace::class)->changeStatus($item, 'active', null);

        return $item;
    }
}
