<?php

namespace App\Support;

use App\Models\AdvertisingCampaign;
use App\Models\Site;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class AdvertisingSchedule
{
    public const CAPACITY = 1;

    /**
     * Private planning selection only. No public renderer invokes this method.
     * The oldest independently approved eligible request takes precedence.
     */
    public function candidates(Site $site, string $placement, ?string $date = null): Collection
    {
        return $this->eligible($site, $placement, $date)->take(self::CAPACITY)->values();
    }

    public function eligible(Site $site, string $placement, ?string $date = null): Collection
    {
        if (! CommercialRecords::enabled() || $site->status !== 'active' || ! array_key_exists($placement, CommercialCatalog::PLACEMENTS)) {
            return collect();
        }
        $date ??= today('America/Winnipeg')->toDateString();
        // Maximum duration is twelve weeks, so the SQL prefilter is bounded.
        $earliest = Carbon::parse($date)->subWeeks(12)->toDateString();

        return AdvertisingCampaign::with('business')->where('site_id', $site->id)
            ->where('placement', $placement)->where('status', 'approved')
            ->whereNotNull('reviewed_at')->whereBetween('starts_on', [$earliest, $date])
            ->whereHas('business', fn ($query) => $query->where('status', 'active'))
            ->orderBy('reviewed_at')->orderBy('id')->get()
            ->filter(fn ($item) => $item->planningPhase($date) === 'in-window' && CommercialRecords::eligible($item->business, $site, $placement))
            ->values();
    }
}
