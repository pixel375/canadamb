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
        $this->get('https://canadamb.ca/account/properties?status=draft&q=outbuilding')->assertOk()->assertSee($draft->title)->assertSee('1 draft found');
        $this->get('https://canadamb.ca/account/properties?status=reviewed')->assertOk()->assertDontSee($draft->title);
        $this->get('https://canadamb.ca/account/properties?location='.$draft->location_id.'&min_price=300000&max_price=320000')->assertOk()->assertSee($draft->title);
        $this->get('https://canadamb.ca/account/properties?location='.$outside)->assertOk()->assertDontSee($draft->title);
        $this->get('https://canadamb.ca/account/properties?min_price=400000')->assertOk()->assertDontSee($draft->title);
        $this->get('https://canadamb.ca/account/properties?max_price=320000')->assertOk()->assertSee($draft->title);
        $this->getJson('https://canadamb.ca/account/properties?min_price=400000&max_price=300000')->assertUnprocessable();
        $this->get('https://canadamb.ca/account/properties/new?location=brandon&transaction=rent')->assertOk()
            ->assertSee('value="rent" selected', false)->assertSee('value="'.$draft->location_id.'" selected', false);
        $this->actingAs($other)->get('https://canadamb.ca/account/properties')->assertOk()->assertDontSee($draft->title);
        $this->actingAs($author);
        $this->get($thumbnail)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->actingAs($other)->get($path)->assertNotFound();
        $this->get($thumbnail)->assertNotFound();
        $this->actingAs($author)->get($path)->assertOk()
            ->assertSee('name="authorization_capacity"', false)
            ->assertSee('name="advertising_consent"', false);
        $this->post("$path/submit", ['version' => $draft->contentVersion()])->assertSessionHasErrors(['authorization_capacity', 'advertising_consent']);
        $this->post("$path/submit", [
            'version' => $draft->fresh()->contentVersion(), 'authorization_capacity' => 'owner',
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
        $this->get('https://canadamb.ca/admin/properties?status=submitted&q=outbuilding&location='.$draft->location_id)->assertOk()->assertSee($draft->title);
        $this->get('https://canadamb.ca/admin/properties?status=submitted&location='.$outside)->assertOk()->assertDontSee($draft->title);
        $this->get('https://canadamb.ca/admin/properties?status=reviewed')->assertOk()->assertDontSee($draft->title);
        $this->post($review, ['version' => $draft->fresh()->contentVersion(), 'action' => 'publish'])->assertSessionHasErrors('action');
        $this->post($review, ['version' => $draft->fresh()->contentVersion(), 'action' => 'review', 'note' => 'The draft has the required internal fields.'])
            ->assertSessionHasErrors(['owner_authority_checked', 'seller_role_checked',
                'property_details_checked', 'media_checked']);
        $this->post($review, [
            'version' => $draft->fresh()->contentVersion(), 'action' => 'review',
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

    public function test_batch_submission_requires_authority_and_current_versions_for_all_selected_properties(): void
    {
        config(['network.property_drafts' => true]);
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $items = collect();
        foreach (['brandon', 'brandon', 'winnipeg'] as $index => $slug) {
            $items->push(PropertyListing::create([
                'owner_id' => $owner->id, 'location_id' => Location::where('slug', $slug)->sole()->id,
                'title' => 'Batch draft '.$index, 'description' => 'A private property submitted for independent review.',
                'transaction' => 'sale', 'property_type' => 'house', 'seller_type' => 'owner', 'price_cents' => 20000000,
            ]));
        }
        $base = 'https://brandonmb.ca/account/properties';
        $versions = $items->mapWithKeys(fn ($item) => [$item->id => $item->fresh()->contentVersion()])->all();
        $data = ['properties' => [$items[0]->id, $items[1]->id], 'versions' => $versions, 'authorization_capacity' => 'owner', 'advertising_consent' => '1'];
        $this->actingAs($other)->post($base.'/submit-selected', $data)->assertNotFound();
        $this->actingAs($owner)->get($base)->assertOk()->assertSee('Submit selected properties for review');
        $this->post($base.'/submit-selected', array_replace($data, ['advertising_consent' => '0']))->assertSessionHasErrors('advertising_consent');
        $this->post($base.'/submit-selected', array_replace($data, ['properties' => [$items[0]->id, $items[2]->id]]))->assertNotFound();
        $this->post($base.'/submit-selected', array_replace($data, ['versions' => array_replace($versions, [$items[1]->id => str_repeat('0', 64)])]))->assertSessionHasErrors('properties');
        $this->assertSame('draft', $items[0]->fresh()->status);
        $this->post($base.'/submit-selected', $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('submitted', $items[0]->fresh()->status);
        $this->assertSame('submitted', $items[1]->fresh()->status);
        $this->assertSame('draft', $items[2]->fresh()->status);
        $this->assertDatabaseCount('audit_logs', 2);
        $this->get('https://brandonmb.ca/real-estate')->assertOk()->assertDontSee('Batch draft');
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
            'version' => $draft->fresh()->contentVersion(), 'authorization_capacity' => 'owner',
            'advertising_consent' => '1',
        ])->assertRedirect();
        $version = $draft->fresh()->contentVersion();
        $this->actingAs($owner)->post($path.'/withdraw-review', ['version' => $version])->assertRedirect();
        $this->assertSame('draft', $draft->fresh()->status);
        $this->actingAs($moderator)->get($review)->assertNotFound();
        $this->actingAs($owner)->post($path.'/submit', [
            'version' => $draft->fresh()->contentVersion(), 'authorization_capacity' => 'owner', 'advertising_consent' => '1',
        ])->assertRedirect();
        $this->actingAs($moderator)->post($review, [
            'version' => $draft->fresh()->contentVersion(), 'action' => 'review', 'note' => 'The authority and property details were checked.',
            'owner_authority_checked' => '1', 'seller_role_checked' => '1',
            'property_details_checked' => '1', 'media_checked' => '1',
        ])->assertRedirect();
        $this->post($review, [
            'version' => $draft->fresh()->contentVersion(), 'action' => 'request-changes',
            'note' => 'Please confirm the updated property description.',
        ])->assertRedirect();
        $this->assertSame('changes_requested', $draft->fresh()->status);

        $this->actingAs($owner)->delete($path)->assertRedirect();
        $this->assertSame('archived', $draft->fresh()->status);
        $history = 'https://brandonmb.ca/account/properties/history';
        $this->get($history.'?reference='.$draft->id)->assertOk()->assertSee($draft->title)->assertDontSee($draft->description)->assertDontSee('Create similar draft');
        $this->actingAs($moderator)->get($history)->assertOk()->assertDontSee($draft->title);
        $this->actingAs($owner)->get('https://canadamb.ca/account/properties/history?q=family')->assertOk()->assertSee($draft->title);
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $owner->id, 'action' => 'property-draft.withdrawn']);
        $this->get($path)->assertNotFound();
        $this->get('https://brandonmb.ca/real-estate/'.$draft->id)->assertNotFound();
    }

    public function test_retained_property_photos_are_private_scoped_and_version_bound(): void
    {
        Storage::fake('local');
        config(['network.property_drafts' => true]);
        $owner = User::factory()->create();
        $admin = User::factory()->create(['role' => 'network_admin']);
        $base = 'https://brandonmb.ca/account/properties';
        $data = [
            'title' => 'Property photo management', 'description' => 'A sufficiently detailed private property description.',
            'transaction' => 'sale', 'property_type' => 'house', 'seller_type' => 'owner', 'price' => '250000',
        ];
        $this->actingAs($owner)->post($base, $data + ['photos' => [
            UploadedFile::fake()->image('first.jpg'), UploadedFile::fake()->image('second.jpg'),
        ]])->assertRedirect()->assertSessionHasNoErrors();
        $draft = PropertyListing::sole();
        $path = $base.'/'.$draft->id;
        $initial = $draft->photos()->get();
        $version = $draft->contentVersion();
        $this->put($path, $data + ['version' => $version, 'manage_photos' => 1, 'keep_photos' => [$initial[1]->id, 999999]])
            ->assertSessionHasErrors('photos');
        $this->assertCount(2, $draft->photos()->get());
        $this->put($path, $data + ['version' => $version, 'manage_photos' => 1, 'keep_photos' => $initial->pluck('id')->all()])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('audit_logs', ['action' => 'property-draft.revised']);
        $this->assertSame($version, $draft->fresh()->contentVersion());
        $this->put($path, $data + ['version' => $version, 'manage_photos' => 1, 'keep_photos' => [$initial[1]->id],
            'photos' => [UploadedFile::fake()->image('third.jpg')]])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($initial[1]->id, $draft->photos()->first()->id);
        $this->assertCount(2, $draft->photos()->get());
        $this->get($path.'/media/'.$initial[1]->id.'?size=full')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get($path.'/duplicate')->assertOk()->assertSee('Property photo management')->assertSee('Private address, legal land description');
        $this->assertDatabaseCount('property_listings', 1);
        $this->actingAs($admin)->get($path.'/duplicate')->assertNotFound();
        $this->get($path.'/media/'.$initial[1]->id.'?size=full')->assertNotFound();
        $this->actingAs($owner);
        Storage::disk('local')->assertMissing($initial[0]->image_path);
        Storage::disk('local')->assertExists($initial[1]->image_path);
        $this->put($path, $data + ['version' => $version])->assertConflict();
        $this->post($path.'/submit', ['version' => $draft->fresh()->contentVersion(), 'authorization_capacity' => 'owner', 'advertising_consent' => '1'])->assertRedirect();
        $reviewVersion = $draft->fresh()->contentVersion();
        $this->actingAs($admin)->get('https://canadamb.ca/admin/properties/'.$draft->id)->assertOk();
        $this->actingAs($owner)->get($base)->assertOk()->assertSee('Return selected properties to draft');
        $this->post($base.'/withdraw-selected', ['properties' => [$draft->id], 'versions' => [$draft->id => str_repeat('0', 64)], 'confirm_withdraw' => '1'])->assertSessionHasErrors('properties');
        $this->assertSame('submitted', $draft->fresh()->status);
        $this->post($base.'/withdraw-selected', ['properties' => [$draft->id], 'versions' => [$draft->id => $reviewVersion], 'confirm_withdraw' => '1'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertCount(2, $draft->photos()->get());
        $this->assertSame('draft', $draft->fresh()->status);
    }
}
