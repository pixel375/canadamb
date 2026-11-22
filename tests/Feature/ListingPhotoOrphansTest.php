<?php

namespace Tests\Feature;

use App\Models\Listing;
use App\Models\Location;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ListingPhotoOrphansTest extends TestCase
{
    use RefreshDatabase;

    protected $seeder = DatabaseSeeder::class;

    public function test_orphan_recovery_requires_opt_in_and_checks_age_path_and_references(): void
    {
        $disk = Storage::fake('listing_photo_orphans_test');
        config(['network.media_disk' => 'listing_photo_orphans_test']);
        $seller = User::factory()->create();
        $location = Location::where('slug', 'brandon')->sole();
        $referenced = [];

        foreach (['pending', 'active', 'removed'] as $status) {
            $listing = Listing::create([
                'user_id' => $seller->id, 'location_id' => $location->id, 'category' => 'other',
                'title' => 'Referenced photo', 'description' => 'Keep this photo with the listing.',
                'condition' => 'good', 'price_cents' => 0, 'status' => $status,
            ]);
            $uuid = Str::uuid();
            $media = $listing->media()->create([
                'image_path' => "listings/$uuid.webp",
                'thumbnail_path' => "listings/$uuid-preview.webp",
                'position' => 0,
            ]);
            $referenced[] = $media->image_path;
            $referenced[] = $media->thumbnail_path;
        }

        $uuid = Str::uuid();
        $orphaned = ["listings/$uuid.webp", "listings/$uuid-preview.webp"];
        $unrelated = ['listings/upload.zip', 'listings/subdir/'.Str::uuid().'.webp', 'property-drafts/'.Str::uuid().'.webp'];
        $fresh = 'listings/'.Str::uuid().'.webp';
        foreach ([...$referenced, ...$orphaned, ...$unrelated] as $path) {
            $disk->put($path, 'older file');
            $this->assertTrue(touch($disk->path($path), now()->subDays(2)->timestamp));
        }
        $disk->put($fresh, 'in-flight upload');

        $this->artisan('marketplace:photo-orphans')->assertSuccessful();
        $disk->assertExists([...$referenced, ...$orphaned, ...$unrelated, $fresh]);

        $this->artisan('marketplace:photo-orphans --apply --limit=1')->assertSuccessful();
        $this->assertCount(1, array_filter($orphaned, fn ($path) => $disk->exists($path)));
        $this->artisan('marketplace:photo-orphans --apply')->assertSuccessful();
        $disk->assertMissing($orphaned);
        $disk->assertExists([...$referenced, ...$unrelated, $fresh]);
        $this->assertDatabaseCount('listing_media', 3);
    }
}
