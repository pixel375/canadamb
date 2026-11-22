<?php

namespace Tests\Feature;

use App\Models\Listing;
use App\Models\ListingMedia;
use App\Models\Location;
use App\Models\User;
use App\Support\ListingPhotoOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class MarketplacePhotoWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private const CITY = 'https://brandonmb.ca';

    private const MAIN = 'https://canadamb.ca';

    private const DISK = 'marketplace_photos_test';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(self::DISK);
        config(['network.media_disk' => self::DISK]);
    }

    public function test_creates_zero_one_two_and_five_photos_in_declared_order(): void
    {
        $seller = User::factory()->create();
        $this->actingAs($seller);
        foreach ([0, 1, 2, 5] as $count) {
            $files = [];
            $refs = [];
            for ($index = 0; $index < $count; $index++) {
                $files[] = UploadedFile::fake()->image("photo-$index.png", 100 + $index, 60);
                array_unshift($refs, "new:$index");
            }
            $this->post(self::CITY.'/marketplace', $this->payload("Multi-photo listing $count") + [
                'photo_plan' => json_encode($refs), 'photos' => $files,
            ])->assertSessionHasNoErrors()->assertRedirect('/account/listings');
            $listing = Listing::latest('id')->firstOrFail();
            $this->assertCount($count, $listing->media);
            foreach ($listing->media as $index => $photo) {
                $this->assertSame($index, $photo->position);
                $this->assertMatchesRegularExpression('~^listings/[a-f0-9-]+\.webp$~', $photo->image_path);
                $info = getimagesizefromstring(Storage::disk(self::DISK)->get($photo->image_path));
                $this->assertSame(100 + $count - 1 - $index, $info[0]);
            }
        }
        $this->assertCount(16, Storage::disk(self::DISK)->allFiles('listings'));
    }

    public function test_edits_keep_noop_approval_and_can_remove_add_reorder_replace_and_clear(): void
    {
        $member = User::factory()->create();
        $listing = $this->createListing($member, 3);
        [$first, $second, $third] = $listing->media->all();
        $listing->forceFill(['status' => 'active', 'published_at' => now(), 'expires_at' => now()->addMonth()])->save();
        $url = $this->listingUrl($listing);
        $version = $this->version($listing);
        $existing = ["existing:$first->id", "existing:$second->id", "existing:$third->id"];
        $this->put($url, $this->payload($listing->title) + [
            'photo_plan' => json_encode($existing), 'photo_version' => $version,
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('active', $listing->fresh()->status);
        $this->assertDatabaseCount('listing_status_history', 1);

        $this->put($url, $this->payload($listing->title) + [
            'photo_plan' => json_encode(["existing:$third->id", 'new:0', "existing:$first->id"]), 'photo_version' => $version,
            'photos' => [UploadedFile::fake()->image('replacement.png', 220, 120)],
        ])->assertSessionHasNoErrors()->assertRedirect();
        $retained = $listing->media()->get();
        $this->assertSame([$third->id, $first->id], [$retained[0]->id, $retained[2]->id]);
        $this->assertSame([0, 1, 2], $retained->pluck('position')->all());
        Storage::disk(self::DISK)->assertMissing($second->image_path);
        Storage::disk(self::DISK)->assertExists([$first->image_path, $third->thumbnail_path]);
        $this->assertSame('pending', $listing->fresh()->status);

        $this->put($url, $this->payload($listing->title) + ['photo_version' => $version, 'photo_plan' => '[]'])
            ->assertConflict();
        $this->assertSame($retained->pluck('id')->all(), $listing->media()->pluck('id')->all());
        $this->put($url, $this->payload($listing->title) + ['photo_version' => $this->version($listing), 'photo_plan' => '[]'])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseCount('listing_media', 0);
        $this->assertSame([], Storage::disk(self::DISK)->allFiles('listings'));
    }

    public function test_bad_plan_or_foreign_media_is_rejected_without_touching_existing_files(): void
    {
        $owner = User::factory()->create();
        $listing = $this->createListing($owner, 5);
        $other = $this->createListing(User::factory()->create(), 1);
        $this->actingAs($owner);
        $version = $this->version($listing);
        $ids = $listing->media()->pluck('id')->all();
        $before = Storage::disk(self::DISK)->allFiles();
        $existing = array_map(fn (int $id) => "existing:$id", $ids);
        foreach ([
            [...$existing, 'new:0'],
            ['existing:'.$other->media->first()->id, 'new:0'],
            [$existing[0], $existing[0], 'new:0'],
            ['new:1'],
            [$existing[0]],
        ] as $refs) {
            $this->from($this->listingUrl($listing).'/edit')->put($this->listingUrl($listing), $this->payload($listing->title) + [
                'photo_version' => $version, 'photo_plan' => json_encode($refs),
                'photos' => [UploadedFile::fake()->image('unwanted.png')],
            ])->assertSessionHasErrors('photo_plan');
        }
        $this->assertSame($ids, $listing->media()->pluck('id')->all());
        $this->assertSame($before, Storage::disk(self::DISK)->allFiles());
    }

    public function test_upload_validation_and_rollback_during_edit_preserve_originals(): void
    {
        $member = User::factory()->create();
        $listing = $this->createListing($member, 1);
        $rural = $this->createListing($member, 1, 'elkhorn');
        $version = $this->version($listing);
        $url = $this->listingUrl($listing);
        $files = Storage::disk(self::DISK)->allFiles();
        $backing = UploadedFile::fake()->createWithContent('spoof.png', 'A non-image file.');
        $invalid = new UploadedFile($backing->getPathname(), 'spoof.png', 'image/png', UPLOAD_ERR_OK, true);
        $this->put($url, $this->payload('Changed description') + ['photos' => [$invalid]])->assertSessionHasErrors('photos.0');
        $this->put($url, $this->payload('Changed description') + [
            'photos' => [UploadedFile::fake()->image('large.png')->size(4097)],
        ])->assertSessionHasErrors('photos.0');
        $this->put($this->listingUrl($rural), $this->payload('Invalid city update') + ['photo_version' => ListingPhotoOrder::version($rural->fresh())])->assertNotFound();
        $this->assertSame($files, Storage::disk(self::DISK)->allFiles());

        ListingMedia::creating(fn () => throw new RuntimeException('Simulated persistence failure.'));
        $this->withoutExceptionHandling();
        try {
            $this->put($url, $this->payload('Changed description') + [
                'photo_version' => $version, 'photo_plan' => '["new:0"]',
                'photos' => [UploadedFile::fake()->image('replacement.png')],
            ]);
            $this->fail('The simulated persistence failure should escape the controller.');
        } catch (RuntimeException $error) {
            $this->assertSame('Simulated persistence failure.', $error->getMessage());
        }
        $this->assertSame($files, Storage::disk(self::DISK)->allFiles());
        $this->assertSame($listing->title, $listing->fresh()->title);
        $this->assertDatabaseCount('listing_media', 2);
    }

    public function test_legacy_form_retains_without_uploads_and_replaces_with_uploads(): void
    {
        $listing = $this->createListing(User::factory()->create(), 2);
        $before = $listing->media()->pluck('id')->all();
        $this->put($this->listingUrl($listing), $this->payload('New title from legacy form') + ['photo_version' => $this->version($listing)])->assertRedirect();
        $this->assertSame($before, $listing->media()->pluck('id')->all());
        $this->put($this->listingUrl($listing), $this->payload('New title from legacy form') + [
            'photo_version' => $this->version($listing),
            'photos' => [UploadedFile::fake()->image('replacement.png')],
        ])->assertRedirect();
        $this->assertCount(1, $listing->media()->get());
        $this->assertNotContains($listing->media()->sole()->id, $before);
        $this->assertCount(2, Storage::disk(self::DISK)->allFiles());
    }

    public function test_moderation_previews_photos_and_rejects_a_stale_review(): void
    {
        $seller = User::factory()->create();
        $admin = User::factory()->create(['role' => 'network_admin']);
        $listing = $this->createListing($seller, 1);
        $original = $listing->media->firstOrFail();
        $review = self::MAIN.'/admin/moderation';
        $stale = ListingPhotoOrder::version($listing);
        $this->actingAs($admin)->get($review)->assertOk()
            ->assertSee("/marketplace/$listing->id/thumbnail/$original->id")->assertSee($stale);
        $this->actingAs($seller)->put($this->listingUrl($listing), $this->payload($listing->title) + [
            'photo_version' => $stale,
            'photos' => [UploadedFile::fake()->image('new-photo.png')],
        ])->assertRedirect();
        $this->actingAs($admin)->post("$review/$listing->id", ['status' => 'active', 'version' => $stale])->assertConflict();
        $this->assertDatabaseHas('listings', ['id' => $listing->id, 'status' => 'pending']);
        $this->assertDatabaseCount('notifications', 0);
        $this->post("$review/$listing->id", ['status' => 'active', 'version' => ListingPhotoOrder::version($listing->fresh())])->assertRedirect();
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_replacement_never_deletes_a_file_still_referenced_by_another_row(): void
    {
        $owner = User::factory()->create();
        $first = $this->createListing($owner, 1);
        $original = $first->media->firstOrFail();
        $other = $this->createListing($owner, 1);
        $other->media->firstOrFail()->update(['thumbnail_path' => $original->image_path]);
        $this->put($this->listingUrl($first), $this->payload($first->title) + [
            'photo_version' => $this->version($first),
            'photos' => [UploadedFile::fake()->image('new-photo.png')],
        ])->assertSessionHasNoErrors()->assertRedirect();
        Storage::disk(self::DISK)->assertExists($original->image_path);
        Storage::disk(self::DISK)->assertMissing($original->thumbnail_path);
    }

    public function test_edit_after_expiry_keeps_expiry_history_and_notice_at_the_previous_location(): void
    {
        $seller = User::factory()->create();
        $listing = $this->createListing($seller, 1);
        $brandon = $listing->location_id;
        $listing->forceFill(['status' => 'active', 'published_at' => now()->subMonth(), 'expires_at' => now()->subMinute()])->save();
        $rural = Location::where('slug', 'elkhorn')->sole()->id;
        $this->put(self::MAIN.'/account/listings/'.$listing->id, $this->payload('Edited after expiry') + [
            'photo_version' => ListingPhotoOrder::version($listing->fresh()),
            'location_id' => $rural,
        ])->assertRedirect();
        $this->assertDatabaseHas('listings', ['id' => $listing->id, 'location_id' => $rural, 'status' => 'pending']);
        $this->assertDatabaseHas('listing_status_history', ['listing_id' => $listing->id, 'previous_status' => 'active', 'status' => 'expired']);
        $this->assertDatabaseHas('listing_status_history', ['listing_id' => $listing->id, 'previous_status' => 'expired', 'status' => 'pending']);
        $this->assertDatabaseHas('notification_locations', ['location_id' => $brandon]);
        $this->assertDatabaseHas('notifications', ['user_id' => $seller->id, 'event' => 'listing.expired']);
    }

    public function test_validation_rerender_keeps_the_submitted_revision_and_handles_malformed_old_input(): void
    {
        $listing = $this->createListing(User::factory()->create(), 1);
        $version = $this->version($listing);
        $url = $this->listingUrl($listing);
        $listing->update(['title' => 'Newer title from a different tab']);
        $this->from($url.'/edit')->put($url, array_replace($this->payload('Unexpected array'), [
            'title' => ['invalid'],
            'description' => 'The current tab has a saved draft.',
            'photo_version' => $version,
        ]))->assertSessionHasErrors('title');

        $this->withCookie(config('session.cookie'), session()->getId())->get($url.'/edit')->assertOk()
            ->assertSee('The current tab has a saved draft.')->assertSee('name="photo_version" value="'.$version.'"', false);
        $this->put($url, $this->payload('Old tab tries to overwrite the edit') + ['photo_version' => $version])->assertConflict();
        $this->assertSame('Newer title from a different tab', $listing->fresh()->title);
    }

    public function test_only_verified_owner_or_moderator_can_see_pending_media_on_the_correct_portal(): void
    {
        $seller = User::factory()->create();
        $listing = $this->createListing($seller, 1, 'elkhorn');
        $photo = $listing->media->firstOrFail();
        $path = "/marketplace/$listing->id/thumbnail/$photo->id";
        $this->get(self::MAIN.$path)->assertOk()->assertHeader('cache-control', 'no-store, private');
        $this->get(self::CITY.$path)->assertNotFound();
        $this->actingAsGuest()->get(self::MAIN.$path)->assertNotFound();
        $this->actingAs(User::factory()->create())->get(self::MAIN.$path)->assertNotFound();
        $seller->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($seller)->get(self::MAIN.$path)->assertNotFound();
        $this->actingAs(User::factory()->create(['role' => 'network_admin']))->get(self::MAIN.$path)->assertOk();
    }

    private function createListing(User $seller, int $count, string $location = 'brandon'): Listing
    {
        $data = $this->payload('Furniture set '.$seller->id);
        $data['location_id'] = Location::where('slug', $location)->sole()->id;
        $data['photos'] = array_map(fn (int $id) => UploadedFile::fake()->image("upload-$id.png"), range(1, $count));
        $this->actingAs($seller)->post(self::MAIN.'/marketplace', $data)->assertSessionHasNoErrors()->assertRedirect();

        return Listing::latest('id')->firstOrFail();
    }

    private function payload(string $title): array
    {
        return ['title' => $title, 'description' => 'Includes seating for four people.', 'category' => 'furniture', 'condition' => 'good', 'price' => '50.00'];
    }

    private function listingUrl(Listing $listing): string
    {
        return self::CITY.'/account/listings/'.$listing->id;
    }

    private function version(Listing $listing): string
    {
        return $this->get($this->listingUrl($listing).'/edit')->assertOk()->viewData('photoVersion');
    }
}
