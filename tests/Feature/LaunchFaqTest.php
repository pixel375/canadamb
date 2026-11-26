<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LaunchFaqTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_faq_is_available_on_both_hosts_and_explains_shared_accounts_and_publication(): void
    {
        foreach (['canadamb.ca', 'brandonmb.ca'] as $host) {
            $this->get('https://'.$host.'/faq')->assertOk()
                ->assertSee('Your CanadaMB account works across the network.')
                ->assertSee('Approved posts for a local community can appear on both CanadaMB')
                ->assertSee('href="/faq"', false);
        }
    }
}
