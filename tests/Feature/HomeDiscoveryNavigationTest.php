<?php

namespace Tests\Feature;

use App\Models\CommunityEvent;
use App\Models\CommunityThread;
use App\Models\Location;
use App\Models\Site;
use App\Models\User;
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
            ->assertSee('href="/real-estate"', false)
            ->assertSee('href="/businesses"', false)
            ->assertSee('href="/community"', false)
            ->assertSee('href="/events"', false)
            ->assertSee('href="/jobs"', false)
            ->assertSee('href="/communities"', false);
    }

    public function test_active_community_portals_are_discovered_dynamically_and_drafts_remain_hidden(): void
    {
        $virden = Location::where('slug', 'virden')->sole();
        $prototype = Site::where('code', 'brandon')->sole();
        $portal = Site::create(['code' => 'virden', 'location_id' => $virden->id, 'name' => 'VirdenMB', 'description' => 'Community fixture.',
            'subtitle' => 'Manitoba network', 'tagline' => 'Local community', 'status' => 'active', 'accent' => $prototype->accent, 'overrides' => []]);
        $portal->domain()->create(['host' => 'virdenmb.ca']);
        Site::where('code', 'winnipeg')->sole()->update(['name' => 'Winnipeg draft portal', 'status' => 'draft']);
        $this->get('https://canadamb.ca/?location=virden')->assertOk()->assertSee('Dedicated community websites')
            ->assertSee('https://virdenmb.ca')->assertSee('VirdenMB')->assertDontSee('wpgmb.ca')->assertDontSee('Winnipeg draft portal')
            ->assertSee('href="/marketplace?view=all&amp;location=virden"', false);
        $this->get('https://canadamb.ca/communities?portal=dedicated')->assertOk()->assertSee('Visit VirdenMB')->assertSee('Visit BrandonMB')->assertDontSee('wpgmb.ca');
        $this->get('https://canadamb.ca/communities?portal=network&q=Virden')->assertOk()->assertSee('No matching locations.');
        $this->getJson('https://canadamb.ca/api/v1/locations?portal=dedicated')->assertOk()->assertJsonFragment(['slug' => 'virden'])->assertJsonMissing(['slug' => 'winnipeg']);
        $this->get('https://canadamb.ca/marketplace?location=virden')->assertOk()->assertSee('href="/marketplace/new?location=virden"', false);
    }

    public function test_brandon_home_links_to_its_current_portal_and_keeps_module_scope(): void
    {
        $this->get('https://brandonmb.ca/')->assertOk()
            ->assertSee('Explore Brandon')
            ->assertSee('href="/marketplace"', false)
            ->assertSee('href="/real-estate"', false)
            ->assertSee('href="/businesses"', false)
            ->assertSee('href="/community"', false)
            ->assertSee('href="/events"', false)
            ->assertSee('href="/jobs"', false)
            ->assertDontSee('href="/communities"', false);
    }

    public function test_empty_homepage_still_shows_all_enabled_community_sections_and_real_actions(): void
    {
        foreach (['https://canadamb.ca/', 'https://brandonmb.ca/'] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertSee('Community activity')
                ->assertSee($url === 'https://canadamb.ca/' ? 'Around Manitoba' : 'Around Brandon')
                ->assertSee('class="community-activity home-community-grid"', false)
                ->assertSee('Start a local conversation')
                ->assertSee('Have an event to share?')
                ->assertSee('Find work nearby')
                ->assertSee('href="/community/new"', false)
                ->assertSee('href="/community/new?type=event"', false)
                ->assertSee('href="/jobs"', false)
                ->assertSee('Browse the directory')
                ->assertSee('Browse categories')
                ->assertSee('No published news here yet');
        }
    }

    public function test_community_activity_uses_real_approved_posts_events_and_location_scope(): void
    {
        $this->travelTo('2026-11-24 12:00:00');
        $author = User::factory()->create();
        $brandon = Location::where('slug', 'brandon')->sole();
        $winnipeg = Location::where('slug', 'winnipeg')->sole();

        $local = CommunityThread::create([
            'author_id' => $author->id, 'location_id' => $brandon->id,
            'title' => 'Brandon neighbourhood cleanup',
            'category' => 'general', 'body' => 'Planning a local cleanup with our neighbours.',
        ]);
        $local->forceFill(['status' => 'approved'])->save();

        $hidden = CommunityThread::create([
            'author_id' => $author->id, 'location_id' => $brandon->id,
            'title' => 'Unapproved discussion must stay private',
            'category' => 'general', 'body' => 'Not yet reviewed by a moderator.',
        ]);
        $hidden->forceFill(['status' => 'pending'])->save();

        $remote = CommunityThread::create([
            'author_id' => $author->id, 'location_id' => $winnipeg->id,
            'title' => 'Winnipeg neighbourhood news',
            'category' => 'general', 'body' => 'A discussion in a different community.',
        ]);
        $remote->forceFill(['status' => 'approved'])->save();

        $event = CommunityEvent::create([
            'author_id' => $author->id, 'location_id' => $brandon->id,
            'title' => 'Brandon winter food drive',
            'body' => 'We are collecting donations for local families.',
            'category' => 'community', 'venue' => 'Community centre',
            'organizer' => 'Local volunteers', 'starts_at' => now()->addDays(3)->utc()->format('Y-m-d H:i:s'),
        ]);
        $event->forceFill(['status' => 'approved'])->save();

        $this->get('https://brandonmb.ca/')
            ->assertOk()
            ->assertSee('Brandon neighbourhood cleanup')
            ->assertSee('Brandon winter food drive')
            ->assertSee('href="/community/'.$local->id.'"', false)
            ->assertSee('href="/events/'.$event->id.'"', false)
            ->assertDontSee('Winnipeg neighbourhood news')
            ->assertDontSee('Unapproved discussion must stay private')
            ->assertDontSee('Start a local conversation')
            ->assertDontSee('Have an event to share?');

        $this->get('https://canadamb.ca/?location=brandon')
            ->assertOk()
            ->assertSee('Around Brandon')
            ->assertSee('Brandon neighbourhood cleanup')
            ->assertSee('Brandon winter food drive')
            ->assertDontSee('Winnipeg neighbourhood news');

        $this->get('https://canadamb.ca/')
            ->assertOk()
            ->assertSee('Winnipeg neighbourhood news')
            ->assertSee('Brandon neighbourhood cleanup');
    }

    public function test_home_search_uses_real_site_scoped_get_routes_without_javascript(): void
    {
        $this->get('https://canadamb.ca/?location=brandon')
            ->assertOk()
            ->assertSee('Search Brandon')
            ->assertSee('aria-label="Search your community"', false)
            ->assertSee('data-home-search-section="marketplace" action="/marketplace" method="get"', false)
            ->assertSee('data-home-search-section="businesses" action="/businesses" method="get"', false)
            ->assertSee('data-home-search-section="community" action="/community" method="get"', false)
            ->assertSee('data-home-search-section="jobs" action="/jobs" method="get"', false)
            ->assertSee('data-home-search-section="news" action="/news" method="get"', false)
            ->assertSee('data-home-search-section="events" action="/events" method="get"', false)
            ->assertSee('name="location" value="brandon"', false)
            ->assertSee('name="location" value="Brandon"', false);

        $this->get('https://brandonmb.ca/')
            ->assertOk()
            ->assertSee('Search Brandon')
            ->assertSee('action="/marketplace" method="get"', false)
            ->assertSee('action="/community" method="get"', false)
            ->assertDontSee('name="location" value="brandon"', false);
    }

    public function test_remembered_community_is_explicit_and_never_overrides_city_portal(): void
    {
        $this->post('https://canadamb.ca/communities/preference', ['location' => 'brandon'])
            ->assertRedirect('/?location=brandon')->assertCookie('canadamb_community', 'brandon');
        $this->withCookie('canadamb_community', 'brandon')
            ->get('https://canadamb.ca/')->assertRedirect('/?location=brandon');
        $this->get('https://canadamb.ca/?location=')->assertOk()->assertSee('Explore Manitoba');
        $this->get('https://canadamb.ca/?location=winnipeg')->assertOk()->assertSee('Explore Winnipeg');
        $this->get('https://canadamb.ca/communities?q=Brandon')->assertOk()
            ->assertSee('Your preferred community: Brandon');
        $this->get('https://canadamb.ca/marketplace')->assertOk();

        $this->withCookie('canadamb_community', 'winnipeg')
            ->get('https://brandonmb.ca/')->assertOk()->assertSee('Explore Brandon');
        $this->post('https://brandonmb.ca/communities/preference', ['location' => 'winnipeg'])->assertNotFound();

        $this->postJson('https://canadamb.ca/communities/preference', ['location' => 'not-a-real-community'])
            ->assertUnprocessable()->assertJsonValidationErrors('location');
        $this->post('https://canadamb.ca/communities/preference', ['location' => ''])
            ->assertRedirect('/')->assertCookieExpired('canadamb_community');
    }

    public function test_quick_search_targets_the_current_enabled_section_and_keeps_community(): void
    {
        $this->get('https://canadamb.ca/jobs?location=Brandon&q=mechanic')
            ->assertOk()->assertSee('class="header-search" action="/jobs"', false)
            ->assertSee('class="site-drawer-search" action="/jobs"', false)
            ->assertSee('name="location" value="Brandon"', false)->assertSee('value="mechanic"', false);
        $this->get('https://canadamb.ca/news?location=brandon&q=council')
            ->assertOk()->assertSee('class="header-search" action="/news"', false)
            ->assertSee('class="site-drawer-search" action="/news"', false)
            ->assertSee('name="location" value="brandon"', false);
        $this->get('https://brandonmb.ca/business-updates')->assertOk()
            ->assertSee('class="header-search" action="/business-updates"', false);
    }

    public function test_community_search_escapes_wildcards_and_combines_words(): void
    {
        $this->getJson('https://canadamb.ca/api/v1/locations?q=BRAN%20DON')->assertOk()
            ->assertJsonFragment(['slug' => 'brandon']);
        $this->getJson('https://canadamb.ca/api/v1/locations?q=%25')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('https://canadamb.ca/api/v1/locations?q=_')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('https://canadamb.ca/api/v1/locations?q=brandon%20notarealplace')->assertOk()->assertJsonCount(0, 'data');
        $this->get('https://canadamb.ca/communities?q=brandon')->assertOk()->assertSee('Reset filters')->assertSee('found');
    }

    public function test_category_discovery_search_is_real_submission_not_a_dummy_button(): void
    {
        $this->get('https://canadamb.ca/marketplace')->assertOk()
            ->assertSee('class="market-quick-search" action="/marketplace" method="get"', false)
            ->assertSee('name="q"', false)
            ->assertSee('Search listings')
            ->assertSee('Real Estate')
            ->assertSee('href="/real-estate"', false)
            ->assertSee('Coming later');
    }
}
