<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DesktopNavigationHotfixTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_unreleased_live_baseline_has_desktop_business_and_discussion_menus(): void
    {
        foreach (['https://canadamb.ca', 'https://brandonmb.ca'] as $domain) {
            $this->get($domain.'/')->assertOk()
                ->assertSee('class="desktop-nav-group"', false)
                ->assertSee('Businesses')
                ->assertSee('Discussions')
                ->assertSee('href="/account/messages"', false)
                ->assertSee('class="desktop-real-estate"', false)
                ->assertSee('href="/real-estate"', false);
        }
    }

    public function test_verified_member_has_a_desktop_messages_shortcut(): void
    {
        $member = User::factory()->create();
        $this->actingAs($member)->get('https://canadamb.ca/')->assertOk()
            ->assertSee('class="sign-in header-inbox" href="/account/messages"', false)
            ->assertSee('aria-label="My profile"', false)
            ->assertSee('class="header-account-menu"', false)
            ->assertSee('aria-label="Account menu"', false)
            ->assertSee('href="/account/settings"', false)
            ->assertSee('action="/account/logout"', false);
        $this->actingAs($member)->get('https://brandonmb.ca/')->assertOk()
            ->assertSee('class="sign-in header-inbox" href="/account/messages"', false);
    }
}
