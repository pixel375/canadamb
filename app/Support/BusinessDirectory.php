<?php

namespace App\Support;

use App\Models\Business;
use App\Models\BusinessUpdate;
use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Normalizer;

final class BusinessDirectory
{
    public const CATEGORIES = [
        'retail' => 'Retail', 'food' => 'Food & drink', 'services' => 'Professional & local services',
        'trades' => 'Trades & construction', 'health' => 'Health & wellness', 'agriculture' => 'Agriculture',
        'arts' => 'Arts & recreation', 'other' => 'Other',
    ];

    public function enabled(Site $site): bool
    {
        return app(NetworkSettings::class)->forSite($site)['modules']['businesses'] ?? false;
    }

    public function visible(Site $site): Builder
    {
        return Business::where('status', 'active')->whereHas('locations', fn ($branch) => $branch
            ->when($site->location_id, fn ($local, $id) => $local->where('location_id', $id)));
    }

    public function updates(?int $location): Builder
    {
        return BusinessUpdate::where('status', 'approved')->whereHas('branch', fn ($branch) => $branch
            ->whereHas('business', fn ($business) => $business->where('status', 'active'))
            ->when($location, fn ($local, $id) => $local->where('location_id', $id)))
            ->with(['branch.business', 'branch.location'])->latest('published_at')->orderByDesc('id');
    }

    public function canonicalSite(Business $business): Site
    {
        $business->loadMissing('locations.location.site.domain');
        if ($business->locations->pluck('location_id')->unique()->count() > 1) {
            return NetworkUrl::main();
        }
        $primary = $business->locations->sortBy('id')->first();
        $site = $primary?->location?->site;

        return $site?->status === 'active' && $this->enabled($site) ? $site : NetworkUrl::main();
    }

    public static function addressKey(string $name, string $address): string
    {
        $normalize = static fn (string $value) => Str::of($value)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->trim()->toString();

        return hash('sha256', $normalize($name).'|'.$normalize($address));
    }

    public static function normalizedText(string $value): string
    {
        return trim(preg_replace('/\p{Z}+/u', ' ', Normalizer::normalize($value, Normalizer::FORM_C)));
    }

    public static function identityNameKey(string $name): string
    {
        return hash('sha256', mb_convert_case(self::normalizedText($name), MB_CASE_FOLD, 'UTF-8'));
    }

    public static function slug(string $name): string
    {
        return (Str::slug(Str::limit($name, 100, '')) ?: 'business').'-'.Str::lower(Str::random(8));
    }
}
