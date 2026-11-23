<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomeDiscoveryNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_provincial_home_has_real_module_aware_shortcuts(): void
    {
        $this->get('https://canadamb.ca/')->assertOk()
            ->assertSee('Explore Manitoba')
            ->assertSee('href="/marketplace"', false)
            ->assertSee('href="/businesses"', false)
            ->assertSee('href="/discussions"', false)
            ->assertSee('href="/events"', false)
            ->assertSee('href="/jobs"', false)
            ->assertSee('href="/communities"', false);
    }

    public function test_brandon_home_links_to_its_current_portal_and_keeps_module_scope(): void
    {
        $this->get('https://brandonmb.ca/')->assertOk()
            ->assertSee('Explore Brandon')
            ->assertSee('href="/marketplace"', false)
            ->assertSee('href="/businesses"', false)
            ->assertSee('href="/discussions"', false)
            ->assertSee('href="/events"', false)
            ->assertSee('href="/jobs"', false)
            ->assertDontSee('href="/communities"', false);
    }

    public function test_category_discovery_search_is_real_submission_not_a_dummy_button(): void
    {
        $this->get('https://canadamb.ca/marketplace')->assertOk()
            ->assertSee('class="market-quick-search" action="/marketplace" method="get"', false)
            ->assertSee('name="q"', false)
            ->assertSee('Search listings')
            ->assertSee('Real Estate')
            ->assertSee('Coming later');
    }
}
