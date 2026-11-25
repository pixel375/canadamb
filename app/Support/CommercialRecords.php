<?php

namespace App\Support;

use App\Models\Business;
use App\Models\Site;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class CommercialRecords
{
    public static function enabled(): bool
    {
        return (bool) config('network.commercial_planning_records')
            && Schema::hasTable('advertising_campaigns') && Schema::hasTable('business_entitlements');
    }

    public static function eligible(Business $business, Site $site, string $placement): bool
    {
        if ($business->status !== 'active' || $site->status !== 'active' || ! array_key_exists($placement, CommercialCatalog::PLACEMENTS)) {
            return false;
        }
        if ($site->location_id && ! $business->locations()->where('location_id', $site->location_id)->exists()) {
            return false;
        }
        $module = CommercialCatalog::PLACEMENTS[$placement];

        return $module === null || (app(NetworkSettings::class)->forSite($site)['modules'][$module] ?? false);
    }

    public static function hasTrial(Business $business): bool
    {
        if (! self::enabled() || $business->status !== 'active') {
            return false;
        }
        $today = today('America/Winnipeg')->toDateString();

        return DB::table('business_entitlements')->where('business_id', $business->id)->where('product', 'business-pro')
            ->whereNull('revoked_at')->where('starts_on', '<=', $today)->where('ends_on', '>', $today)->exists();
    }
}
