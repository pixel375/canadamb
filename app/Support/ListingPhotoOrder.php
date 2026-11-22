<?php

namespace App\Support;

use App\Models\Listing;
use App\Models\ListingMedia;
use Illuminate\Validation\ValidationException;

final class ListingPhotoOrder
{
    public static function version(Listing $listing): string
    {
        return hash('sha256', json_encode([
            $listing->getRawOriginal(),
            $listing->media->map(fn (ListingMedia $photo) => [$photo->id, $photo->position])->all(),
        ], JSON_THROW_ON_ERROR));
    }

    public static function references(Listing $listing, ?array $requested, array $uploads): array
    {
        if ($requested === null) {
            return $uploads === [] ? self::existing($listing) : array_map(fn ($key) => "new:$key", array_keys($uploads));
        }

        $retained = array_filter($requested, fn (string $reference) => str_starts_with($reference, 'existing:'));
        if (array_diff($retained, self::existing($listing)) !== []) {
            throw ValidationException::withMessages(['photo_plan' => 'One of these photos no longer belongs to this listing. Reload the edit form.']);
        }

        return $requested;
    }

    public static function existing(Listing $listing): array
    {
        return $listing->media->map(fn (ListingMedia $photo) => "existing:$photo->id")->all();
    }

    /** The caller holds the parent listing lock until the media changes commit. */
    public static function save(Listing $listing, array $references, array $uploads): array
    {
        $existing = $listing->media->toBase()->keyBy(fn (ListingMedia $photo) => "existing:$photo->id");
        $removed = $existing->except($references);
        if ($removed->isNotEmpty()) {
            $listing->media()->whereIn('id', $removed->pluck('id'))->delete();
        }

        // Move retained rows out of the final positions before swapping any two rows.
        $temporary = 5;
        $taken = $existing->pluck('position')->all();
        foreach ($existing->only($references) as $photo) {
            while (in_array($temporary, $taken, true)) {
                $temporary++;
            }
            $photo->update(['position' => $temporary++]);
        }
        foreach ($references as $position => $reference) {
            if ($photo = $existing->get($reference)) {
                $photo->update(['position' => $position]);
            } else {
                $upload = $uploads[(int) substr($reference, 4)];
                $listing->media()->create(array_replace($upload, ['position' => $position]));
            }
        }

        return $removed->values()->toArray();
    }
}
