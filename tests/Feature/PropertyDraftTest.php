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
        $this->post("$path/submit", [
            'authorization_capacity' => 'owner',
            'advertising_consent' => '1',
        ])->assertRedirect();
        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $author->id,
            'action' => 'property-draft.authority-declared',
        ]);
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
        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $admin->id,
            'action' => 'property-draft.review',
        ]);
        $this->get("https://brandonmb.ca/real-estate/$draft->id")->assertNotFound();
    }

    public function test_private_review_can_be_reopened_and_withdrawal_is_audited(): void
    {
        config(['network.property_drafts' => true]);
        $owner = User::factory()->create();
        $moderator = User::factory()->create(['role' => 'network_admin']);
        $draft = PropertyListing::create([
            'owner_id' => $owner->id,
            'location_id' => Location::where('slug', 'brandon')->sole()->id,
            'title' => 'Private family property',
            'description' => 'A home draft used to verify the internal review audit.',
            'transaction' => 'sale',
            'property_type' => 'house',
            'seller_type' => 'owner',
            'price_cents' => 22000000,
        ]);
        $path = 'https://brandonmb.ca/account/properties/'.$draft->id;
        $review = 'https://canadamb.ca/admin/properties/'.$draft->id;

        $this->actingAs($owner)->post($path.'/submit', [
            'authorization_capacity' => 'owner',
            'advertising_consent' => '1',
        ])->assertRedirect();
        $this->actingAs($moderator)->post($review, [
            'action' => 'review', 'note' => 'The authority and property details were checked.',
            'owner_authority_checked' => '1', 'seller_role_checked' => '1',
            'property_details_checked' => '1', 'media_checked' => '1',
        ])->assertRedirect();
        $this->post($review, [
            'action' => 'request-changes',
            'note' => 'Please confirm the updated property description.',
        ])->assertRedirect();
        $this->assertSame('changes_requested', $draft->fresh()->status);

        $this->actingAs($owner)->delete($path)->assertRedirect();
        $this->assertSame('archived', $draft->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $owner->id, 'action' => 'property-draft.withdrawn']);
        $this->get($path)->assertNotFound();
        $this->get('https://brandonmb.ca/real-estate/'.$draft->id)->assertNotFound();
    }
}
