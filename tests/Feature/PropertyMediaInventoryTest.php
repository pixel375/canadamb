<?php

namespace Tests\Feature;

use App\Models\Listing;
use App\Models\Location;
use App\Models\User;
use App\Support\PropertyMediaInventory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PropertyMediaInventoryTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_media_inventory_is_bounded_and_never_deletes_files_or_exposes_paths(): void
    {
        Storage::fake('local');
        config(['network.media_disk' => 'local']);
        $disk = Storage::disk('local');
        $old = 'listings/11111111-1111-1111-1111-111111111111.webp';
        $recent = 'listings/22222222-2222-2222-2222-222222222222.webp';
        $referenced = 'listings/33333333-3333-3333-3333-333333333333.webp';
        $property = 'property-drafts/44444444-4444-4444-4444-444444444444.webp';
        foreach ([$old, $recent, $referenced, $property] as $file) {
            $disk->put($file, 'fixture');
        }
        foreach ([$old, $referenced, $property] as $file) {
            touch($disk->path($file), now()->subDays(3)->timestamp);
        }
        $listing = Listing::create(['user_id' => User::factory()->create()->id, 'location_id' => Location::where('slug', 'brandon')->sole()->id,
            'title' => 'Inventory fixture', 'description' => 'A photo reference fixture.', 'category' => 'furniture', 'condition' => 'good', 'price_cents' => 1000]);
        $listing->media()->create(['image_path' => $referenced, 'thumbnail_path' => str_replace('.webp', '-preview.webp', $referenced), 'position' => 0]);
        $inventory = app(PropertyMediaInventory::class);
        $result = $inventory->inspect(10);
        $this->assertSame(3, $result['marketplace']['files_examined']);
        $this->assertSame(1, $result['marketplace']['old_unreferenced_files']);
        $this->assertSame(1, $result['marketplace']['missing_files']);
        $this->assertSame(1, $result['private_properties']['old_unreferenced_files']);
        $this->assertTrue($inventory->inspect(1)['marketplace']['files_truncated']);
        $this->assertStringNotContainsString($old, json_encode($result, JSON_THROW_ON_ERROR));
        $disk->assertExists([$old, $recent, $referenced, $property]);
        $this->artisan('media:inventory', ['--limit' => 0])->assertFailed();
    }
}
