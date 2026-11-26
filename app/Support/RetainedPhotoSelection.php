<?php

namespace App\Support;

use Illuminate\Support\Collection;

final class RetainedPhotoSelection
{
    public static function forEditor(Collection $photos, bool $matchingVersion, mixed $previous): array
    {
        $current = $photos->keyBy('id');
        $fallback = ['photos' => $photos, 'keep' => $current->keys()->all(), 'restored' => false];
        if (! $matchingVersion) {
            return $fallback;
        }
        $previous ??= [];
        if (! is_array($previous) || ! array_is_list($previous)) {
            return $fallback;
        }
        $keep = [];
        foreach ($previous as $value) {
            if ((! is_int($value) && ! (is_string($value) && ctype_digit($value))) || ! $current->has((int) $value) || in_array((int) $value, $keep, true)) {
                return $fallback;
            }
            $keep[] = (int) $value;
        }

        return ['photos' => collect($keep)->map(fn ($id) => $current[$id])->concat($current->except($keep)->values()), 'keep' => $keep, 'restored' => true];
    }
}
