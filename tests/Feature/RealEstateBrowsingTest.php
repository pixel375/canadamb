<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\PropertyListing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RealEstateBrowsingTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_both_hosts_have_a_real_property_browser_without_exposing_private_reviewed_drafts(): void
    {
        $private = $this->property('brandon', 'Private Brandon family home', 'reviewed');
        $private->forceFill([
            'private_address' => '999 Secret Road',
            'legal_land_description' => 'Secret legal lot details',
        ])->save();

        foreach (['canadamb.ca', 'brandonmb.ca'] as $host) {
            $this->get('https://'.$host.'/real-estate')->assertOk()
                ->assertSee('<h1 id="property-heading">Real Estate</h1>', false)
                ->assertSee('Property listings are being prepared')
                ->assertSee('Browse by property type')
                ->assertSee('For sale')
                ->assertSee('For rent')
                ->assertDontSee('Private Brandon family home')
                ->assertDontSee('999 Secret Road')
                ->assertDontSee('Secret legal lot details');

            $this->get('https://'.$host.'/real-estate/'.$private->id)->assertNotFound();
            $this->get('https://'.$host.'/real-estate/'.$private->id.'/photo/1')->assertNotFound();
            $this->get('https://'.$host.'/marketplace')->assertOk()->assertSee('href="/real-estate"', false);
        }

        $this->get('https://canadamb.ca/real-estate?transaction=rent&property_type=apartment&location=brandon')
            ->assertOk()->assertSee('value="apartment" selected', false);
        $this->get('https://canadamb.ca/real-estate?location=brandon&transaction=rent&q=garage')->assertOk()
            ->assertSee('Townhouses')->assertSee('Duplexes')->assertSee('Industrial')
            ->assertSee('property_type=house&amp;q=garage&amp;transaction=rent&amp;location=brandon', false);
        $this->get('https://brandonmb.ca/real-estate?location=elkhorn')->assertOk()
            ->assertDontSee('name="location"', false);
    }

    public function test_public_property_gate_and_review_status_are_both_required(): void
    {
        $listed = $this->property('brandon', 'Potential published ranch', 'published');
        $reviewed = $this->property('brandon', 'Never published private rental', 'reviewed', 'rent');
        $listed->forceFill(['private_address' => '100 Private Lane', 'legal_land_description' => 'NW secret quarter'])->save();
        $listed->photos()->create([
            'image_path' => 'property-drafts/first-full.webp',
            'thumbnail_path' => 'property-drafts/first-thumb.webp',
            'position' => 0,
        ]);
        Storage::fake('local');
        Storage::disk('local')->put('property-drafts/first-thumb.webp', 'fake webp bytes');
        $photo = $listed->photos()->first();

        // A manually entered 'published' status alone cannot bypass rollout.
        $this->get('https://brandonmb.ca/real-estate')->assertDontSee('Potential published ranch');
        $this->get('https://brandonmb.ca/real-estate/'.$listed->id)->assertNotFound();
        $this->get('https://brandonmb.ca/real-estate/'.$listed->id.'/photo/'.$photo->id)->assertNotFound();

        // Future public visibility still needs an explicit approved status and city match.
        config(['network.real_estate_public_listings' => true]);
        $this->get('https://brandonmb.ca/real-estate')->assertOk()
            ->assertSee('Potential published ranch')
            ->assertDontSee('Never published private rental')
            ->assertDontSee('100 Private Lane')
            ->assertDontSee('NW secret quarter');
        $this->get('https://brandonmb.ca/real-estate/'.$listed->id)->assertOk()
            ->assertSee('Potential published ranch')
            ->assertDontSee('100 Private Lane')
            ->assertDontSee('NW secret quarter');
        $this->get('https://brandonmb.ca/real-estate/'.$listed->id.'/photo/'.$photo->id)
            ->assertOk()->assertHeader('Content-Type', 'image/webp');
        $this->get('https://brandonmb.ca/real-estate/'.$reviewed->id)->assertNotFound();
        $this->get('https://canadamb.ca/real-estate?transaction=rent')->assertDontSee('Potential published ranch');
        $this->get('https://canadamb.ca/real-estate?location=elkhorn')->assertDontSee('Potential published ranch');
        $this->get('https://canadamb.ca/real-estate?property_type=house&min_price=350001')->assertDontSee('Potential published ranch');
        $this->get('https://canadamb.ca/real-estate?property_type=house&max_price=350000')
            ->assertSee('Potential published ranch');
    }

    public function test_filters_are_validated_and_only_supported_cities_are_allowed(): void
    {
        $this->get('https://canadamb.ca/real-estate?transaction=illegal')->assertSessionHasErrors('transaction');
        $this->get('https://canadamb.ca/real-estate?property_type=unknown')->assertSessionHasErrors('property_type');
        $this->get('https://canadamb.ca/real-estate?min_price=-1')->assertSessionHasErrors('min_price');
        $this->get('https://canadamb.ca/real-estate?sort=sql')->assertSessionHasErrors('sort');
        $this->get('https://canadamb.ca/real-estate?location=outside-province')->assertSessionHasErrors('location');
    }

    private function property(string $slug, string $title, string $status, string $transaction = 'sale'): PropertyListing
    {
        $item = PropertyListing::create([
            'owner_id' => User::factory()->create()->id,
            'location_id' => Location::where('slug', $slug)->sole()->id,
            'title' => $title,
            'description' => 'A property record used to test public visibility and filters.',
            'transaction' => $transaction,
            'property_type' => 'house',
            'seller_type' => 'owner',
            'price_cents' => 35_000_000,
            'bedrooms' => 3,
        ]);
        $item->forceFill(['status' => $status])->save();

        return $item;
    }
}
