<?php

namespace App\Support;

use App\Models\Location;
use App\Models\Site;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class SignupLocations
{
    public static function enabled(): bool
    {
        return (bool) config('network.signup_locations') && Schema::hasTable('user_signup_locations');
    }

    public static function choices(): Collection
    {
        return Location::selectable()->orderBy('name')->orderBy('id')->get(['id', 'name']);
    }

    public static function forRegistration(array $input, ?Site $site): ?int
    {
        if (! config('network.signup_locations')) {
            return null;
        }
        abort_unless(self::enabled() && $site, 503);
        $data = Validator::make($input, [
            'location_id' => $site->isNetwork() ? 'required|integer|min:1' : 'nullable|integer|min:1',
            'location_public' => 'accepted',
        ])->validate();
        $id = $site->isNetwork() ? (int) $data['location_id'] : (int) $site->location_id;
        if (! $site->isNetwork() && isset($data['location_id']) && (int) $data['location_id'] !== $id) {
            throw ValidationException::withMessages(['location_id' => 'Register on CanadaMB.ca if you live outside this community.']);
        }
        if (! Location::selectable()->whereKey($id)->lockForUpdate()->first()) {
            throw ValidationException::withMessages(['location_id' => 'Select an available Manitoba community.']);
        }

        return $id;
    }

    public static function publicName(int $member): ?string
    {
        if (! self::enabled()) {
            return null;
        }

        return DB::table('user_signup_locations')->join('locations', 'locations.id', '=', 'user_signup_locations.location_id')
            ->where('user_signup_locations.user_id', $member)->where('locations.province', 'MB')->where('locations.selectable', true)->value('locations.name');
    }
}
