<?php

namespace Tests\Feature;

use App\Models\Listing;
use App\Models\Location;
use App\Models\MarketplaceSearch;
use App\Models\User;
use App\Support\StagedFeatures;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Tests\TestCase;

class MarketplaceSearchesTest extends TestCase
{
    use DatabaseMigrations;

    public function runDatabaseMigrations()
    {
        $this->artisan('migrate:fresh', ['--seed' => true, '--seeder' => DatabaseSeeder::class])->assertSuccessful();
        $this->artisan('migrate', ['--path' => 'database/proposed-migrations'])->assertSuccessful();
        $this->beforeApplicationDestroyed(function () {
            $this->artisan('migrate:rollback', ['--path' => 'database/proposed-migrations'])->assertSuccessful();
            RefreshDatabaseState::$migrated = false;
            RefreshDatabaseState::$inMemoryConnections = [];
        });
    }

    public function test_saved_marketplace_filters_are_private_and_match_public_scope(): void
    {
        $member = User::factory()->create();
        $seller = User::factory()->create();
        foreach ([
            ['brandon', 'Oak desk', 'active', 2000],
            ['brandon', 'Oak hidden', 'pending', 2000],
            ['elkhorn', 'Oak rural', 'active', 2000],
            ['brandon', 'Oak expensive', 'active', 9000],
        ] as [$place, $title, $status, $price]) {
            $listing = Listing::create([
                'user_id' => $seller->id, 'location_id' => Location::where('slug', $place)->sole()->id,
                'category' => 'furniture', 'title' => $title, 'description' => 'Furniture fixture',
                'status' => $status, 'condition' => 'good', 'price_cents' => $price,
            ]);
            $listing->forceFill(['published_at' => now(), 'expires_at' => now()->addWeek()])->save();
        }
        $base = 'https://brandonmb.ca/account/marketplace-searches';
        $filters = ['name' => 'My furniture', 'q' => 'Oak', 'category' => 'furniture', 'max_price' => '50', 'location' => 'elkhorn'];
        $this->actingAs($member)->post($base, $filters)->assertNotFound();
        config(['network.marketplace_searches' => true, 'network.extended_messaging' => true, 'network.commercial_planning_records' => true]);
        $inventory = StagedFeatures::inspect();
        foreach ($inventory as $feature) {
            $this->assertSame([], $feature['problems'], $feature['label']);
            $this->assertTrue($feature['ready'], $feature['label']);
        }
        $this->post($base, $filters)->assertRedirect()->assertSessionHasNoErrors();
        $search = MarketplaceSearch::sole();
        $this->assertArrayNotHasKey('location', $search->filters);
        $this->post($base, array_replace($filters, ['max_price' => '50.00', 'sort' => 'price_asc']))->assertSessionHasErrors('name');
        $this->get($base)->assertOk()->assertSee('1 matching listing')->assertSee('My furniture')->assertSee('Keywords: Oak')->assertSee('Maximum price: $50.00');
        $this->get('https://brandonmb.ca/account/profile')->assertSee('Saved Marketplace searches');
        $this->get('https://brandonmb.ca/account/saved')->assertSee('Marketplace searches');
        $this->get('https://brandonmb.ca/marketplace?'.http_build_query($search->filters))->assertSee('Oak desk')->assertDontSee('Oak hidden')->assertDontSee('Oak rural')->assertDontSee('Oak expensive');
        $path = $base.'/'.$search->id;
        $this->actingAs($seller)->post($path.'/rename', ['name' => 'Stolen'])->assertNotFound();
        $this->delete($path)->assertNotFound();
        $version = $search->fresh()->editVersion();
        $this->actingAs($member)->post($path.'/rename', ['name' => 'Updated furniture', 'version' => $version])->assertRedirect();
        $this->put($path, $filters + ['version' => $version])->assertSessionHasErrors('version');
        $this->delete($path, ['version' => $version])->assertSessionHasErrors('version');
        $this->get($base)->assertSee('Updated furniture');
        $this->delete('https://canadamb.ca/account/marketplace-searches/'.$search->id)->assertNotFound();
        $this->get($path.'/edit')->assertOk()->assertSee('Updated furniture');
        $this->actingAs($seller)->put($path, $filters)->assertNotFound();
        $this->actingAs($member)->put($path, array_replace($filters, ['name' => 'Expanded furniture', 'max_price' => '100']))->assertRedirect()->assertSessionHasNoErrors();
        $this->get($base)->assertSee('2 matching listings')->assertSee('Expanded furniture');
        $this->delete($path)->assertRedirect();
        $this->assertDatabaseCount('marketplace_searches', 0);
    }
}
