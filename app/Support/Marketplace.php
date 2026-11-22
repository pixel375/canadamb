<?php

namespace App\Support;

use App\Models\Listing;
use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class Marketplace
{
    public const CATEGORIES = [
        'cars' => 'Cars & Trucks', 'powersports' => 'Motorcycles & Powersports', 'rvs' => 'RVs & Campers',
        'trailers' => 'Trailers', 'auto-parts' => 'Auto Parts', 'furniture' => 'Furniture', 'appliances' => 'Appliances',
        'home' => 'Home & Garden', 'electronics' => 'Electronics', 'computers' => 'Computers', 'phones' => 'Phones',
        'gaming' => 'Gaming', 'tools' => 'Tools', 'farm' => 'Farm Equipment', 'outdoor' => 'Outdoor Equipment',
        'sports' => 'Sports', 'clothing' => 'Clothing', 'collectibles' => 'Collectibles', 'pets' => 'Pet Supplies',
        'kids' => 'Baby & Kids', 'other' => 'Other',
    ];

    public const CONDITIONS = ['new' => 'New', 'like-new' => 'Like new', 'good' => 'Good', 'fair' => 'Fair', 'parts' => 'For parts'];

    public const FIELDS = [
        'cars' => ['make' => 'Make', 'model' => 'Model', 'year' => 'Year', 'mileage' => 'Mileage (km)', 'fuel' => 'Fuel', 'transmission' => 'Transmission'],
        'rvs' => ['make' => 'Manufacturer', 'model' => 'Model', 'year' => 'Year', 'length' => 'Length (ft)', 'slides' => 'Slides', 'type' => 'Type'],
        'electronics' => ['brand' => 'Brand', 'model' => 'Model', 'specifications' => 'Specifications'],
        'farm' => ['make' => 'Make', 'model' => 'Model', 'year' => 'Year', 'hours' => 'Operating hours', 'attachments' => 'Attachments'],
    ];

    public function enabled(Site $site): bool
    {
        return app(NetworkSettings::class)->forSite($site)['modules']['marketplace'] ?? false;
    }

    public function visible(Site $site): Builder
    {
        return Listing::query()->with(['location', 'categoryRecord', 'media'])->active()
            ->when($site->location_id !== null, fn (Builder $query) => $query->where('location_id', $site->location_id));
    }

    public function canonicalSite(Listing $listing): Site
    {
        if ($listing->relationLoaded('location') && $listing->location->relationLoaded('site')) {
            $site = $listing->location->site;
        } else {
            $site = Site::with('domain')->where('location_id', $listing->location_id)->first();
        }

        if ($site && $site->status === 'active' && $this->enabled($site)) {
            return $site;
        }

        return NetworkUrl::main();
    }

    public function history(Listing $listing, ?string $from, ?int $actor, ?string $reason = null): int
    {
        return DB::table('listing_status_history')->insertGetId([
            'listing_id' => $listing->id, 'actor_id' => $actor, 'previous_status' => $from,
            'status' => $listing->status, 'reason' => $reason, 'created_at' => now(),
        ]);
    }

    public function changeStatus(Listing $listing, string $status, ?int $actor, ?string $reason = null, bool $moderated = false): int
    {
        $from = $listing->status;
        $listing->status = $status;
        if ($status === 'active') {
            $listing->published_at = now();
            $listing->expires_at = now()->addDays(30);
        }
        $listing->save();
        $history = $this->history($listing, $from, $actor, $reason);
        $event = match ($status) {
            'active' => 'listing.approved',
            'rejected' => 'listing.rejected',
            'removed' => 'listing.removed',
            'expired' => 'listing.expired',
            default => null,
        };
        if ($event !== null && $from !== $status && ($moderated || $status === 'expired')) {
            NotificationInbox::record($listing->user_id, $event, $history, $listing->id, [$listing->location_id]);
        }

        return $history;
    }
}
