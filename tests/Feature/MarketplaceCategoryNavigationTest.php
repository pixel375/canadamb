<?php

namespace Tests\Feature;

use App\Models\Listing;
use App\Models\Location;
use App\Models\User;
use App\Support\Marketplace;
use App\Support\MarketplaceCategories;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketplaceCategoryNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private const MAIN = 'https://canadamb.ca';

    private const CITY = 'https://brandonmb.ca';

    public function test_existing_leaf_taxonomy_maps_once_into_main_category_groups(): void
    {
        $flat = Marketplace::CATEGORIES;
        $grouped = MarketplaceCategories::grouped($flat);
        $leaf = [];
        foreach ($grouped as $group) {
            foreach ($group['available'] as $slug => $label) {
                $this->assertArrayNotHasKey($slug, $leaf, "A category is duplicated: $slug");
                $this->assertSame($flat[$slug], $label);
                $leaf[$slug] = $label;
            }
        }
        $this->assertEqualsCanonicalizing(array_keys($flat), array_keys($leaf));
        $this->assertSame(['cars', 'powersports', 'rvs', 'trailers', 'auto-parts'],
            MarketplaceCategories::childSlugs('vehicles', $flat));
        $this->assertSame([], MarketplaceCategories::childSlugs('real-estate', $flat));
    }

    public function test_first_visit_shows_main_categories_before_listings_with_explicit_view_all(): void
    {
        $seller = User::factory()->create();
        $this->published($seller, 'Visible Manitoba sports car', 'cars', 'brandon');
        $this->published($seller, 'Visible Manitoba loveseat', 'furniture', 'elkhorn');

        foreach ([self::MAIN, self::CITY] as $portal) {
            $this->get($portal.'/marketplace')->assertOk()
                ->assertSee('What are you looking for?')
                ->assertSee('Vehicles &amp; Motors', false)
                ->assertSee('Home &amp; Living', false)
                ->assertSee('href="/marketplace?view=all"', false)
                ->assertSee('href="/marketplace?group=vehicles"', false)
                ->assertSee('aria-label="Vehicles &amp; Motors subcategories"', false)
                ->assertSee('href="/marketplace?category=cars"', false)
                ->assertSee('href="/marketplace?category=rvs"', false)
                ->assertSee('class="market-category-card market-category-parent"', false)
                ->assertSee('Real Estate')
                ->assertSee('Coming later')
                ->assertSee('href="/real-estate"', false)
                ->assertSee('class="market-category-icon"', false)
                ->assertDontSee('🚗')
                ->assertDontSee('🧸')
                ->assertDontSee('Visible Manitoba sports car');
        }
        $this->get(self::MAIN.'/marketplace?view=all')->assertOk()
            ->assertSee('Visible Manitoba sports car')->assertSee('Visible Manitoba loveseat')
            ->assertSee('Browse categories instead');
        $this->get(self::CITY.'/marketplace?view=all')->assertOk()
            ->assertSee('Visible Manitoba sports car')->assertDontSee('Visible Manitoba loveseat');
    }

    public function test_location_only_visit_remains_category_first_and_keeps_location_scope(): void
    {
        $seller = User::factory()->create();
        $this->published($seller, 'Brandon family car', 'cars', 'brandon');
        $this->published($seller, 'Elkhorn family car', 'cars', 'elkhorn');

        $this->get(self::MAIN.'/marketplace?location=brandon')->assertOk()
            ->assertSee('What are you looking for?')
            ->assertSee('Browsing <strong>Brandon</strong> listings.', false)
            ->assertSee('href="/marketplace?group=vehicles&amp;location=brandon"', false)
            ->assertSee('href="/marketplace?category=cars&amp;location=brandon"', false)
            ->assertSee('href="/marketplace?view=all&amp;location=brandon"', false)
            ->assertSee('type="hidden" name="location" value="brandon"', false)
            ->assertDontSee('Brandon family car');

        $this->get(self::MAIN.'/marketplace?group=vehicles&location=brandon')->assertOk()
            ->assertSee('Cars &amp; Trucks', false)
            ->assertSee('href="/marketplace?category=cars&amp;location=brandon"', false)
            ->assertSee('href="/marketplace?group=vehicles&amp;view=all&amp;location=brandon"', false)
            ->assertSee('href="/marketplace?location=brandon"', false)
            ->assertDontSee('Brandon family car');

        $this->get(self::MAIN.'/marketplace?category=cars&location=brandon')->assertOk()
            ->assertSee('Brandon family car')
            ->assertDontSee('Elkhorn family car')
            ->assertSee('href="/marketplace?group=vehicles&amp;location=brandon"', false);

        $this->get(self::MAIN.'/marketplace?view=all&location=brandon')->assertOk()
            ->assertSee('Brandon family car')
            ->assertDontSee('Elkhorn family car')
            ->assertSee('href="/marketplace?location=brandon"', false);

        // City portals enforce their own scope even when a visitor supplies
        // another town in an unrecognized location query parameter.
        $this->get(self::CITY.'/marketplace?location=elkhorn')->assertOk()
            ->assertSee('What are you looking for?')
            ->assertDontSee('href="/marketplace?group=vehicles&amp;location=elkhorn"', false);
    }

    public function test_main_group_subcategory_direct_legacy_links_and_search_keep_city_permissions(): void
    {
        $seller = User::factory()->create();
        $this->published($seller, 'Used family car', 'cars', 'brandon');
        $this->published($seller, 'Kitchen sofa', 'furniture', 'brandon');
        $this->published($seller, 'Elkhorn truck', 'cars', 'elkhorn');

        $this->get(self::MAIN.'/marketplace?group=vehicles')->assertOk()
            ->assertSee('Cars &amp; Trucks', false)
            ->assertSee('Motorcycles &amp; Powersports', false)
            ->assertSee('href="/marketplace?category=cars"', false)
            ->assertSee('href="/marketplace?group=vehicles&amp;view=all"', false)
            ->assertDontSee('Used family car');
        $this->get(self::MAIN.'/marketplace?group=vehicles&view=all')->assertOk()
            ->assertSee('Used family car')->assertSee('Elkhorn truck')->assertDontSee('Kitchen sofa')
            ->assertSee('All in Vehicles &amp; Motors', false)->assertSee('1 active filter')
            ->assertSee('href="/marketplace?view=all">Search across all categories', false);
        $this->get(self::CITY.'/marketplace?group=vehicles&view=all')->assertOk()
            ->assertSee('Used family car')->assertDontSee('Elkhorn truck')->assertDontSee('Kitchen sofa');
        $this->get(self::MAIN.'/marketplace?category=cars')->assertOk()
            ->assertSee('Used family car')->assertSee('Elkhorn truck')->assertDontSee('Kitchen sofa');
        $this->get(self::MAIN.'/marketplace?group=vehicles&view=all&category=furniture')->assertOk()
            ->assertSee('Kitchen sofa')->assertDontSee('Used family car');
        $this->get(self::MAIN.'/marketplace?group=vehicles&view=all&q=Kitchen&location=brandon')->assertOk()
            ->assertSee('No listings match your search')
            ->assertSee('Search across all categories')
            ->assertSee('href="/marketplace?q=Kitchen&amp;view=all&amp;location=brandon', false);
        $this->get(self::MAIN.'/marketplace?q=Kitchen')->assertOk()
            ->assertSee('Kitchen sofa')->assertDontSee('Elkhorn truck');
        $this->getJson(self::MAIN.'/api/v1/marketplace?group=vehicles')->assertOk()
            ->assertJsonPath('meta.total', 2);
        $this->get(self::MAIN.'/marketplace?group=unrecognized')->assertSessionHasErrors('group');
    }

    public function test_results_pagination_preserves_explicit_all_manitoba_and_category_group(): void
    {
        $seller = User::factory()->create();
        for ($i = 1; $i <= 19; $i++) {
            $this->published($seller, 'Car page fixture '.$i, 'cars', $i % 2 ? 'brandon' : 'elkhorn');
        }
        $this->published($seller, 'Excluded sofa fixture', 'furniture', 'brandon');
        $this->withCookie('canadamb_community', 'brandon')->get(self::MAIN.'/marketplace?group=vehicles&view=all&location=')
            ->assertOk()->assertSee('19 results')->assertSee('name="group" value="vehicles"', false)
            ->assertSee('name="view" value="all"', false)->assertSee('location=&amp;page=2', false)
            ->assertDontSee('Excluded sofa fixture');
        $this->get(self::MAIN.'/marketplace?group=vehicles&view=all&location=&page=2')
            ->assertOk()->assertSee('19 results')->assertSee('Car page fixture 1');
    }

    public function test_posting_select_uses_subcategories_but_real_estate_is_not_publicly_selectable(): void
    {
        $seller = User::factory()->create();
        $this->actingAs($seller)->get(self::MAIN.'/marketplace/new')->assertOk()
            ->assertSee('<optgroup label="Vehicles &amp; Motors">', false)
            ->assertSee('value="cars"', false)
            ->assertSee('value="rvs"', false)
            ->assertDontSee('value="real-estate"', false)
            ->assertSee('Public real estate listings are not yet available.');
    }

    public function test_posting_from_all_manitoba_does_not_preselect_remembered_location(): void
    {
        $member = User::factory()->create();
        $place = Location::where('slug', 'brandon')->sole();
        $this->actingAs($member)->withCookie('canadamb_community', 'brandon')
            ->get(self::MAIN.'/marketplace?location=')->assertOk()
            ->assertSee('href="/marketplace/new?location="', false);
        $this->get(self::MAIN.'/marketplace/new?location=')->assertOk()
            ->assertViewHas('suggestedLocation', fn ($location) => $location === null)
            ->assertDontSee('value="'.$place->id.'" selected', false)
            ->assertSee('href="/marketplace?location="', false);
        $this->get(self::MAIN.'/marketplace/new')->assertOk()
            ->assertViewHas('suggestedLocation', fn ($location) => $location?->id === $place->id);
        $this->get(self::CITY.'/marketplace/new?location=elkhorn')->assertOk()
            ->assertViewHas('suggestedLocation', fn ($location) => $location?->id === $place->id);
    }

    public function test_seller_profiles_keep_temporary_browse_scope_without_changing_inventory_permissions(): void
    {
        $seller = User::factory()->create();
        $first = $this->published($seller, 'Brandon seller fixture', 'cars', 'brandon');
        for ($i = 1; $i <= 12; $i++) {
            $this->published($seller, 'Elkhorn seller fixture '.$i, 'cars', 'elkhorn');
        }
        $profile = '/users/'.$seller->id;
        $this->withCookie('canadamb_community', 'brandon')->get(self::MAIN.'/marketplace/'.$first->id.'?browse=')
            ->assertOk()->assertSee('href="'.$profile.'?browse="', false);
        $page = $this->get(self::MAIN.$profile.'?browse=&category=cars')->assertOk()
            ->assertSee('name="browse" value=""', false)
            ->assertSee('href="'.$profile.'?browse=#member-listings"', false)
            ->assertSee('href="/marketplace?location="', false);
        $this->assertSame(13, $page->viewData('listings')->total());
        $next = $page->viewData('listings')->nextPageUrl();
        $this->assertStringContainsString('browse=', $next);
        $this->assertStringContainsString('listing_page=2', $next);
        $this->get(self::MAIN.$profile.'?browse=&listing_page=2')->assertOk()->assertSee('Brandon seller fixture')
            ->assertSee('href="/marketplace/'.$first->id.'?browse="', false);
        $this->get(self::CITY.$profile.'?browse=elkhorn')->assertOk()->assertSee('Brandon seller fixture')->assertDontSee('Elkhorn seller fixture');
    }

    private function published(User $seller, string $title, string $category, string $town): Listing
    {
        $item = Listing::create([
            'user_id' => $seller->id,
            'location_id' => Location::where('slug', $town)->sole()->id,
            'category' => $category,
            'title' => $title,
            'description' => 'Synthetic community listing for category navigation tests.',
            'condition' => 'good',
            'price_cents' => 5000,
        ]);
        app(Marketplace::class)->changeStatus($item, 'active', null);

        return $item;
    }
}
