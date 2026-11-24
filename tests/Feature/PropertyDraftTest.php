<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\PropertyListing;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PropertyDraftTest extends TestCase
{
    use RefreshDatabase;

    protected $seeder = DatabaseSeeder::class;

    public function test_agent_role_requires_brokerage_and_archive_revokes_review_authority(): void
    {
        config(['network.property_drafts' => true]);
        $owner = User::factory()->create();
        $location = Location::where('slug', 'brandon')->sole();
        $property = PropertyListing::create([
            'owner_id' => $owner->id,
            'location_id' => $location->id,
            'title' => 'Private agent listing',
            'description' => 'A private unit that needs brokerage information.',
            'transaction' => 'sale',
            'property_type' => 'house',
            'seller_type' => 'agent',
            'price_cents' => 10000000,
        ]);
        $url = 'https://brandonmb.ca/account/properties/'.$property->id;

        $this->actingAs($owner)->post($url.'/submit', [
            'authorization_capacity' => 'authorized-agent',
            'advertising_consent' => '1',
        ])->assertStatus(409);
        $this->assertSame('draft', $property->fresh()->status);

        $property->forceFill(['brokerage_name' => 'Verified only by internal reviewer'])->save();
        $this->post($url.'/submit', [
            'authorization_capacity' => 'authorized-agent',
            'advertising_consent' => '1',
        ])->assertRedirect();
        $this->assertSame('submitted', $property->fresh()->status);

        $this->delete($url)->assertRedirect();
        $this->assertSame('archived', $property->fresh()->status);
        $this->assertNotNull($property->fresh()->withdrawn_at);
        $this->get($url)->assertNotFound();
        $this->get('https://brandonmb.ca/real-estate/'.$property->id)->assertNotFound();
    }

    public function test_private_property_drafts_are_location_scoped_and_cannot_be_published_by_review(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        config(['network.media_disk' => 'public']);
        $author = User::factory()->create();
        $other = User::factory()->create();
        $admin = User::factory()->create(['role' => 'network_admin']);
        $origin = 'https://brandonmb.ca';
        $input = [
            'title' => 'Acreage near Brandon', 'transaction' => 'sale', 'property_type' => 'acreage', 'seller_type' => 'owner',
            'description' => 'A private property draft with a large outbuilding.', 'price' => '310000.00', 'bedrooms' => 2,
            'bathrooms' => '1.5', 'floor_area' => 900, 'acreage' => '10.50', 'private_address' => 'Private address sample',
            'photos' => [UploadedFile::fake()->image('outbuilding.jpg')],
        ];
        $this->actingAs($author)->post("$origin/account/properties", $input)->assertNotFound();
        config(['network.property_drafts' => true]);

        $outside = Location::where('slug', 'winnipeg')->sole()->id;
        $this->post("$origin/account/properties", $input + ['location_id' => $outside])->assertSessionHasErrors('location_id');
        $this->post("$origin/account/properties", $input)->assertSessionHasNoErrors()->assertRedirect();
        $draft = PropertyListing::sole();
        $this->assertSame('brandon', $draft->location->slug);
        $this->assertSame(31_000_000, $draft->price_cents);
        $path = "$origin/account/properties/$draft->id";
        $media = $draft->photos()->sole();
        Storage::disk('local')->assertExists($media->thumbnail_path);
        Storage::disk('public')->assertMissing($media->thumbnail_path);
        $thumbnail = "$path/media/$media->id";

        $this->get($path)->assertOk()->assertSee('Private address sample');
        $this->get($thumbnail)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->actingAs($other)->get($path)->assertNotFound();
        $this->get($thumbnail)->assertNotFound();
        $this->actingAs($author)->get($path)->assertOk()
            ->assertSee('name="authorization_capacity"', false)
            ->assertSee('name="advertising_consent"', false);
        $this->post("$path/submit")->assertSessionHasErrors(['authorization_capacity', 'advertising_consent']);
        $this->post("$path/submit", ['authorization_capacity' => 'owner', 'advertising_consent' => '1'])
            ->assertRedirect();
        $this->assertNotNull($draft->fresh()->authorization_attested_at);
        $this->assertSame('owner', $draft->fresh()->authorization_capacity);
        $this->get('https://canadamb.ca/real-estate')->assertOk()->assertDontSee($draft->title)
            ->assertDontSee('Private address sample');
        $this->get('https://canadamb.ca/sitemap.xml')->assertDontSee($draft->title);

        $review = "https://canadamb.ca/admin/properties/$draft->id";
        $this->post($review, ['action' => 'review'])->assertForbidden();
        $this->actingAs($admin)->get($review)->assertOk()->assertSee($draft->title);
        $this->post($review, ['action' => 'publish'])->assertSessionHasErrors('action');
        $this->post($review, ['action' => 'review', 'note' => 'The draft has the required internal fields.'])
            ->assertSessionHasErrors(['owner_authority_checked', 'seller_role_checked',
                'property_details_checked', 'media_checked']);
        $this->post($review, [
            'action' => 'review',
            'note' => 'The draft has the required internal fields.',
            'owner_authority_checked' => '1',
            'seller_role_checked' => '1',
            'property_details_checked' => '1',
            'media_checked' => '1',
        ])->assertRedirect();
        $this->assertDatabaseHas('property_listings', ['id' => $draft->id, 'status' => 'reviewed']);
        $this->assertNotNull($draft->fresh()->review_checklist_completed_at);
        $this->assertNull($draft->fresh()->publication_expires_at);
        $this->get("https://brandonmb.ca/real-estate/$draft->id")->assertNotFound();
    }
}
