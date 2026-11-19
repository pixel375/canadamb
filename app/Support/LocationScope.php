<?php

namespace App\Support;

use App\Models\Location;
use App\Models\Site;
use Illuminate\Validation\ValidationException;

final class LocationScope
{
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
