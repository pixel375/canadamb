<?php

namespace App\Support;

use App\Models\Location;
use App\Models\Site;
use Illuminate\Validation\ValidationException;

final class LocationScope
{
    // Browsing scope is an explicit, shareable GET parameter, never a
    // publication permission or an override for a dedicated city portal.
    public static function forBrowsing(Site $site, mixed $value, bool $byName = false): ?Location
    {
        if (! $site->isNetwork()) {
            return $site->location;
        }

        if (! is_string($value) || trim($value) === '' || mb_strlen($value) > 150) {
            return null;
        }

        return Location::selectable()->where($byName ? 'name' : 'slug', $value)->first();
    }

    public const PREFERENCE_COOKIE = 'canadamb_community';

    public static function preferred(Site $site, mixed $value): ?Location
    {
        return $site->isNetwork() ? self::forBrowsing($site, $value) : null;
    }

    public static function browseUrl(Site $site, string $path, ?Location $place): string
    {
        if (! $site->isNetwork() || ! $place) {
            return $path;
        }

        return $path.'?'.http_build_query([
            'location' => $path === '/jobs' ? $place->name : $place->slug,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public static function forPublication(Site $site, ?int $requested): int
    {
        if ($site->location_id !== null && $requested === null) {
            return $site->location_id;
        }

        if ($site->location_id !== null && $requested !== $site->location_id) {
            throw ValidationException::withMessages(['location_id' => 'Use CanadaMB to post outside this community.']);
        }

        if (! Location::selectable()->whereKey($requested)->exists()) {
            throw ValidationException::withMessages(['location_id' => 'Choose a supported Manitoba location.']);
        }

        return $requested;
    }
}
