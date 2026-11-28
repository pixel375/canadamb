<?php

namespace App\Support;

use App\Models\Location;
use App\Models\Site;
use Illuminate\Validation\ValidationException;

final class LocationScope
{
    public const PREFERENCE_COOKIE = 'canadamb_community';

    private const INDEXES = ['/', 'marketplace', 'real-estate', 'businesses', 'business-updates', 'community', 'jobs', 'events', 'news'];

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

    public static function preferred(Site $site, mixed $value): ?Location
    {
        return $site->isNetwork() ? self::forBrowsing($site, $value) : null;
    }

    /** Content location must never become the visitor's browsing preference. */
    public static function currentBrowsing(Site $site): ?Location
    {
        $request = request();
        if (! $site->isNetwork()) {
            return $site->location;
        }
        if ($request->is(...self::INDEXES) && $request->query->has('location')) {
            return self::forBrowsing($site, $request->query('location'), $request->is('jobs'));
        }

        if (self::detailRequest() && $request->query->has('browse')) {
            return self::forBrowsing($site, $request->query('browse'));
        }

        return self::preferred($site, $request->cookie(self::PREFERENCE_COOKIE));
    }

    private static function detailRequest(): bool
    {
        return (bool) preg_match('~^(?:marketplace|real-estate|businesses|jobs|community|events|news)/[^/]+(?:/(?:follow|save|report|replies(?:/[1-9][0-9]*)?))?$|^account/replies/[1-9][0-9]*(?:/edit)?$|^users/[1-9][0-9]*$|^account/profile$~D', request()->path());
    }

    public static function explicitAll(Site $site): bool
    {
        if (! $site->isNetwork()) {
            return false;
        }

        return self::preferred($site, request()->cookie(self::PREFERENCE_COOKIE)) !== null
            && self::currentBrowsing($site) === null;
    }

    public static function browseUrl(Site $site, string $path, ?Location $place, array $params = []): string
    {
        if ($site->isNetwork() && ($place || self::explicitAll($site))) {
            $params['location'] = $path === '/jobs' ? ($place?->name ?? '') : ($place?->slug ?? '');
        }

        return $path.($params ? '?'.http_build_query($params, '', '&', PHP_QUERY_RFC3986) : '');
    }

    // Carry temporary GET scope only if it differs from the remembered
    // preference. Never use this query parameter to authorize content.
    public static function detailParams(): array
    {
        $site = request()->attributes->get('site');
        if (! $site?->isNetwork()) {
            return [];
        }
        $place = self::currentBrowsing($site);
        $preferred = self::preferred($site, request()->cookie(self::PREFERENCE_COOKIE));

        return $place?->id === $preferred?->id ? [] : ['browse' => $place?->slug ?? ''];
    }

    public static function detailUrl(string $path, array $params = [], string $fragment = ''): string
    {
        $params += self::detailParams();

        return $path.($params ? '?'.http_build_query($params, '', '&', PHP_QUERY_RFC3986) : '').$fragment;
    }

    public static function preserveAll(array $params): array
    {
        $site = request()->attributes->get('site');
        if ($site && self::explicitAll($site)) {
            $params['location'] = '';
        }

        return $params;
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
