<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Listing;
use App\Models\Location;
use App\Models\User;
use App\Support\Marketplace;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MarketplaceEngagementTest extends TestCase
{
    use RefreshDatabase;

    protected $seeder = DatabaseSeeder::class;

    public function test_listing_photo_access_follows_listing_visibility_and_does_not_leak_a_rural_photo(): void
    {
        Storage::fake('local');
        $seller = User::factory()->create();
        $rural = Location::where('slug', 'elkhorn')->sole();
        $this->actingAs($seller)->post('https://canadamb.ca/marketplace', [
            'title' => 'Rural patio set', 'description' => 'Comes with chairs.', 'category' => 'furniture',
            'condition' => 'good', 'price' => '25', 'location_id' => $rural->id,
            'photos' => [UploadedFile::fake()->image('private.jpg', 750, 450)],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $item = Listing::sole();
        $photo = $item->media()->sole();
        Storage::disk('local')->assertExists([$photo->image_path, $photo->thumbnail_path]);
        $path = "/marketplace/$item->id/photo/$photo->id";
        $this->actingAs(User::factory()->create())->get('https://canadamb.ca'.$path)->assertNotFound();
        $this->actingAs(User::factory()->create(['role' => 'network_admin']))
            ->post("https://canadamb.ca/admin/moderation/$item->id", ['status' => 'active'])->assertRedirect();
        $this->actingAs(User::factory()->create())->get('https://canadamb.ca'.$path)->assertOk()->assertHeader('Content-Type', 'image/webp');
        $this->get("https://canadamb.ca/marketplace/$item->id")
            ->assertOk()
            ->assertSee('data-listing-gallery', false)
            ->assertSee('data-gallery-dialog', false)
            ->assertSee('src="'.$path.'"', false)
            ->assertSee('href="'.$path.'"', false);
        $this->get('https://canadamb.ca/marketplace?view=all')
            ->assertOk()
            ->assertSee('aria-label="View Rural patio set"', false)
            ->assertSee('href="/marketplace/'.$item->id.'"', false);
        $this->get('https://brandonmb.ca'.$path)->assertNotFound();
    }

    public function test_multiple_photos_keep_full_resolution_and_can_be_replaced_on_edit(): void
    {
        Storage::fake('local');
        $seller = User::factory()->create();
        $admin = User::factory()->create(['role' => 'network_admin']);
        $brandon = Location::where('slug', 'brandon')->sole();

        $this->actingAs($seller)->post('https://brandonmb.ca/marketplace', [
            'title' => 'Two photos of a desk',
            'description' => 'A desk with two original pictures.',
            'category' => 'furniture',
            'condition' => 'good',
            'price' => '80',
            'photos' => [
                UploadedFile::fake()->image('portrait.jpg', 800, 1200),
                UploadedFile::fake()->image('landscape.jpg', 1200, 800),
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $item = Listing::sole();
        $this->assertSame($brandon->id, $item->location_id);
        $images = $item->media()->get();
        $this->assertCount(2, $images);
        $this->assertSame([0, 1], $images->pluck('position')->all());
        foreach ($images as $photo) {
            Storage::disk('local')->assertExists([$photo->image_path, $photo->thumbnail_path]);
        }

        // Both full-size originals and small thumbnails are generated. The
        // portrait's full image must not be reduced to the 480px thumbnail.
        $full = getimagesizefromstring(Storage::disk('local')->get($images[0]->image_path));
        $thumbnail = getimagesizefromstring(Storage::disk('local')->get($images[0]->thumbnail_path));
        $this->assertSame([800, 1200], array_slice($full, 0, 2));
        $this->assertSame([320, 480], array_slice($thumbnail, 0, 2));

        $this->actingAs($admin)->post("https://canadamb.ca/admin/moderation/$item->id", ['status' => 'active'])->assertRedirect();
        $this->get("https://brandonmb.ca/marketplace/$item->id")
            ->assertOk()
            ->assertSee("src=\"/marketplace/$item->id/photo/{$images[0]->id}\"", false)
            ->assertSee("src=\"/marketplace/$item->id/thumbnail/{$images[1]->id}\"", false)
            ->assertSee('View photo 2 of 2 larger', false);

        $this->actingAs($seller)->put("https://brandonmb.ca/account/listings/$item->id", [
            'title' => 'Desk with updated photo',
            'description' => 'Updated photo shows the entire desk.',
            'category' => 'furniture',
            'condition' => 'good',
            'price' => '80',
            'photos' => [UploadedFile::fake()->image('replacement.jpg', 900, 600)],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $item->refresh();
        $replacement = $item->media()->sole();
        $this->assertNotEquals($images[0]->id, $replacement->id);
        Storage::disk('local')->assertExists([$replacement->image_path, $replacement->thumbnail_path]);
        foreach ($images as $old) {
            Storage::disk('local')->assertMissing([$old->image_path, $old->thumbnail_path]);
        }
        $this->assertSame('pending', $item->status);
    }

    public function test_members_can_save_and_report_a_listing_and_only_participants_can_read_messages(): void
    {
        $seller = User::factory()->create();
        $buyer = User::factory()->create();
        $stranger = User::factory()->create();
        $item = Listing::create([
            'user_id' => $seller->id, 'location_id' => Location::where('slug', 'brandon')->sole()->id,
            'title' => 'Patio set', 'description' => 'Comes with chairs.', 'category' => 'furniture',
            'condition' => 'good', 'price_cents' => 2500, 'status' => 'pending',
        ]);
        app(Marketplace::class)->changeStatus($item, 'active', null);
        $base = "https://brandonmb.ca/marketplace/$item->id";
        $this->actingAs($buyer)->get($base)->assertOk()->assertSee('Save listing')->assertSee('1 active listing');
        $this->post("$base/save", ['return_to' => 'listing'])->assertRedirect('/marketplace/'.$item->id);
        $this->get($base)->assertOk()->assertSee('Remove from saved listings')->assertSee('aria-pressed="true"', false);
        $this->delete("$base/save", ['return_to' => 'listing'])->assertRedirect('/marketplace/'.$item->id);
        $this->get($base)->assertOk()->assertSee('aria-pressed="false"', false);
        $this->post("$base/save")->assertRedirect('/account/saved');
        $this->get('https://canadamb.ca/account/saved')->assertSee('Patio set');
        $this->post("$base/report", ['reason' => 'The images seem to belong to another seller.'])->assertRedirect();
        $this->assertDatabaseHas('listing_reports', ['listing_id' => $item->id, 'reporter_id' => $buyer->id]);
        $this->post("$base/message", ['body' => 'Are the chairs included?'])->assertRedirect();
        $thread = Conversation::sole();
        $this->get($base)->assertOk()->assertSee('Continue conversation');
        $this->actingAs($stranger)->get($base)->assertOk()->assertDontSee('Continue conversation')->assertSee('Save listing');
        $inbox = 'https://brandonmb.ca/account/messages';
        $this->actingAs($stranger)->get($inbox.'?q=Patio')->assertOk()->assertDontSee('Are the chairs included?');
        $this->actingAs($seller)->get($inbox.'?view=unread')->assertOk()->assertSee('Are the chairs included?')->assertSee('1 unread');
        $this->get($inbox.'?q=Patio')->assertOk()->assertSee('Are the chairs included?');
        $this->get($inbox.'?q='.urlencode($buyer->name))->assertOk()->assertSee('Are the chairs included?');
        $this->get($inbox.'?q=%25')->assertOk()->assertDontSee('Are the chairs included?');
        $url = "https://brandonmb.ca/account/messages/$thread->id";
        $this->actingAs($stranger)->get($url)->assertNotFound();
        $this->postJson($url.'/sync', ['after' => 0])->assertNotFound();
        $first = $thread->messages()->sole();
        $this->actingAs($seller)->postJson($url.'/sync', ['after' => 0])->assertOk()
            ->assertJsonPath('messages.0.body', 'Are the chairs included?')
            ->assertJsonPath('messages.0.own', false)
            ->assertJsonPath('counts.messages', 1);
        $this->postJson($url.'/sync', ['after' => $first->id, 'seen' => $first->id])->assertOk()
            ->assertJsonCount(0, 'messages')->assertJsonPath('counts.messages', 0);
        $this->postJson($url.'/sync', ['after' => -1])->assertUnprocessable();
        $this->actingAs($seller)->get($url)->assertOk()->assertSee('Are the chairs included?');
        $this->get($inbox.'?view=unread')->assertOk()->assertDontSee('Are the chairs included?');
        $this->post($url, ['body' => 'Yes, all four are included.'])->assertRedirect();
        $this->actingAs($buyer)->get($url)->assertSee('Yes, all four are included.');
        $this->post("$url/block")->assertRedirect();
        $this->actingAs($seller)->post($url, ['body' => 'Can you still see this message?'])->assertForbidden();
        $this->postJson($url.'/sync', ['after' => $first->id])->assertOk()->assertJsonPath('blocked', true);
        $this->assertDatabaseCount('messages', 2);
    }
}
