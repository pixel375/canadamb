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
            ->assertSee('Used family car')->assertSee('Elkhorn truck')->assertDontSee('Kitchen sofa');
        $this->get(self::CITY.'/marketplace?group=vehicles&view=all')->assertOk()
            ->assertSee('Used family car')->assertDontSee('Elkhorn truck')->assertDontSee('Kitchen sofa');
        $this->get(self::MAIN.'/marketplace?category=cars')->assertOk()
            ->assertSee('Used family car')->assertSee('Elkhorn truck')->assertDontSee('Kitchen sofa');
        $this->get(self::MAIN.'/marketplace?q=Kitchen')->assertOk()
            ->assertSee('Kitchen sofa')->assertDontSee('Elkhorn truck');
        $this->getJson(self::MAIN.'/api/v1/marketplace?group=vehicles')->assertOk()
            ->assertJsonPath('meta.total', 2);
        $this->get(self::MAIN.'/marketplace?group=unrecognized')->assertSessionHasErrors('group');
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
