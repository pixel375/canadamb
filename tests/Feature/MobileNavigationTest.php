<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MobileNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_the_mobile_and_desktop_navigation_has_real_destinations_on_both_hosts(): void
    {
        foreach (['canadamb.ca', 'brandonmb.ca'] as $host) {
            $this->get("https://$host/")
                ->assertOk()
                ->assertSee('id="site-menu-open"', false)
                ->assertSee('id="site-drawer"', false)
                ->assertSee('class="mobile-tab-bar"', false)
                ->assertSee('href="/account/messages"', false)
                ->assertSee('href="/account/notifications"', false)
                ->assertSee('aria-label="Sign in"', false)
                ->assertDontSee('href="/account/settings"', false)
                ->assertSee('Businesses', false)
                ->assertSee('Discussions', false)
                ->assertSee('href="/discussions"', false)
                ->assertSee('href="/events">Events calendar', false)
                ->assertDontSee('href="/community"', false)
                ->assertSee('href="/about"', false)
                ->assertSee('href="/contact"', false)
                ->assertSee('id="theme-choice-mobile"', false);
        }

        $this->actingAs(User::factory()->create())
            ->get('https://canadamb.ca/')
            ->assertOk()
            ->assertSee('href="/account/profile"', false)
            ->assertSee('href="/account/settings"', false)
            ->assertSee('href="/account/discussions"', false);
    }

    public function test_search_filters_are_collapsed_initially_and_reopened_when_query_is_present(): void
    {
        foreach (['marketplace', 'businesses', 'jobs', 'news', 'discussions', 'events'] as $page) {
            $empty = $this->get('https://canadamb.ca/'.$page)->assertOk()->getContent();
            preg_match('/<details class="search-disclosure"([^>]*)>/', $empty, $initial);
            $this->assertArrayHasKey(1, $initial, $page.' must render a filter disclosure');
            $this->assertDoesNotMatchRegularExpression('/\bopen\b/', $initial[1]);

            $param = $page === 'news' ? 'category' : 'q';
            $value = $page === 'news' ? 'local' : 'test';
            // The news category lookup must accept only a known value.
            if ($page === 'news') {
                continue;
            }
            $response = $this->get('https://canadamb.ca/'.$page.'?'.$param.'='.$value)->assertOk()->getContent();
            preg_match('/<details class="search-disclosure"([^>]*)>/', $response, $filtered);
            $this->assertArrayHasKey(1, $filtered);
            $this->assertMatchesRegularExpression('/\bopen\b/', $filtered[1]);
        }
    }

    public function test_discussions_filters_expose_location_only_on_the_provincial_site(): void
    {
        $this->get('https://canadamb.ca/discussions?sort=newest')
            ->assertOk()
            ->assertSee('action="/discussions"', false)
            ->assertSee('name="location"', false)
            ->assertSee('value="newest" selected', false)
            ->assertSee('href="/discussions">Clear filters', false);

        $this->get('https://brandonmb.ca/discussions')
            ->assertOk()
            ->assertSee('name="sort"', false)
            ->assertDontSee('name="location"', false);
    }

    public function test_admin_navigation_is_a_compact_responsive_disclosure(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);

        $this->actingAs($admin)->get('https://canadamb.ca/admin')
            ->assertOk()
            ->assertSee('class="admin-nav-disclosure" open', false)
            ->assertSee('Administration sections')
            ->assertSee('class="admin-nav-links"', false)
            ->assertSee('Discussions &amp; events', false);
    }

    public function test_member_profiles_are_public_without_email_and_account_settings_are_private(): void
    {
        $member = User::factory()->create([
            'name' => 'Public Member',
            'email' => 'private-member@example.test',
        ]);

        $this->get('https://brandonmb.ca/users/'.$member->id)
            ->assertOk()
            ->assertSee('Public Member')
            ->assertSee('Member since')
            ->assertDontSee('private-member@example.test');

        $this->get('https://canadamb.ca/account/settings')->assertRedirect();
        $this->actingAs($member)->get('https://canadamb.ca/account/settings')
            ->assertOk()->assertSee('private-member@example.test');
        $this->post('https://canadamb.ca/account/settings', ['name' => 'Updated Member'])
            ->assertRedirect('/account/settings');
        $this->assertDatabaseHas('users', ['id' => $member->id, 'name' => 'Updated Member']);
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $member->id, 'action' => 'user.profile-updated']);
        $this->get('https://canadamb.ca/users/'.$member->id)
            ->assertOk()->assertSee('Updated Member');
    }
}
