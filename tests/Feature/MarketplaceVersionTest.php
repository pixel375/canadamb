<?php

namespace Tests\Feature;

use App\Models\Listing;
use App\Models\Location;
use App\Models\User;
use App\Support\ListingPhotoOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MarketplaceVersionTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_edits_require_a_current_version_with_or_without_uploads(): void
    {
        Storage::fake('version_guard');
        config(['network.media_disk' => 'version_guard']);
        foreach ([false, true] as $upload) {
            $seller = User::factory()->create();
            $listing = $this->listing($seller);
            $stale = ListingPhotoOrder::version($listing);
            $listing->update(['title' => 'Newer listing content']);
            $url = "https://brandonmb.ca/account/listings/$listing->id";
            $data = ['title' => 'Owner update', 'description' => 'Valid description', 'category' => 'furniture', 'condition' => 'good', 'price' => '10'];
            if ($upload) {
                $data['photos'] = [UploadedFile::fake()->image('test.png')];
            }
            $this->actingAs($seller)->putJson($url, $data)->assertUnprocessable()->assertJsonValidationErrors('photo_version');
            $this->putJson($url, $data + ['photo_version' => 'malformed'])->assertUnprocessable()->assertJsonValidationErrors('photo_version');
            $this->putJson($url, $data + ['photo_version' => $stale])->assertConflict();
            $this->assertSame('Newer listing content', $listing->fresh()->title);
            $this->assertCount(0, $listing->media()->get());
            Storage::disk('version_guard')->assertDirectoryEmpty('listings');
            $this->putJson($url, $data + ['photo_version' => ListingPhotoOrder::version($listing->fresh())])->assertRedirect();
            $this->assertSame('Owner update', $listing->fresh()->title);
            $this->assertCount((int) $upload, $listing->media()->get());
        }
    }

    public function test_approval_rejection_and_removal_require_a_current_version(): void
    {
        $moderator = User::factory()->create(['role' => 'network_admin']);
        foreach (['active', 'rejected', 'removed'] as $status) {
            $listing = $this->listing(User::factory()->create(), $status === 'removed' ? 'active' : 'pending');
            $stale = ListingPhotoOrder::version($listing);
            $previous = $listing->status;
            $listing->update(['title' => 'An updated submission']);
            $url = "https://canadamb.ca/admin/moderation/$listing->id";
            $data = ['status' => $status, 'reason' => 'Reviewed listing content.'];
            $this->actingAs($moderator)->postJson($url, $data)->assertUnprocessable()->assertJsonValidationErrors('version');
            $this->postJson($url, $data + ['version' => ['invalid']])->assertUnprocessable()->assertJsonValidationErrors('version');
            $this->postJson($url, $data + ['version' => $stale])->assertConflict();
            $this->assertSame($previous, $listing->fresh()->status);
            $this->assertDatabaseMissing('listing_status_history', ['listing_id' => $listing->id]);
            $this->postJson($url, $data + ['version' => ListingPhotoOrder::version($listing->fresh())])->assertRedirect();
            $this->assertSame($status, $listing->fresh()->status);
        }
        $this->assertDatabaseCount('notifications', 3);
    }

    private function listing(User $seller, string $status = 'pending'): Listing
    {
        return Listing::forceCreate([
            'user_id' => $seller->id, 'location_id' => Location::where('slug', 'brandon')->sole()->id,
            'status' => $status, 'title' => 'Original listing', 'description' => 'An original description',
            'category' => 'furniture', 'condition' => 'good', 'price_cents' => 2000, 'expires_at' => now()->addDays(30),
        ])->fresh();
    }
}
