<?php

namespace App\Support;

use App\Models\PropertyListing;
use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;

final class PublicProperties
{
    public static function moduleEnabled(Site $site): bool
    {
        return app(Marketplace::class)->enabled($site);
    }

    public static function visible(Site $site): Builder
    {
        return PropertyListing::query()->where('status', 'published')
            ->when(! config('network.real_estate_public_listings') || ! self::moduleEnabled($site), fn (Builder $query) => $query->whereRaw('1 = 0'))
            ->when($site->location_id !== null, fn (Builder $query) => $query->where('location_id', $site->location_id));
    }

    public static function canonicalSite(PropertyListing $property): Site
    {
        $property->loadMissing('location.site.domain');
        $local = $property->location?->site;

        return $local?->status === 'active' && $local->domain && self::moduleEnabled($local) ? $local : NetworkUrl::main();
    }
}
