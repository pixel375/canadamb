<?php

namespace App\Support;

use App\Models\Site;
use Illuminate\Support\Facades\Schema;

final class MarketplaceSearches
{
    public static function enabled(Site $site): bool
    {
        return (bool) config('network.marketplace_searches') && app(Marketplace::class)->enabled($site) && Schema::hasTable('marketplace_searches');
    }
}
