<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class EventSeriesIdentity
{
    public static function enabled(): bool
    {
        return (bool) config('network.event_series_identity') && config('network.recurring_events')
            && Schema::hasTable('event_series_occurrences');
    }

    public static function newKey(int $count): ?string
    {
        return $count > 1 && self::enabled() ? (string) Str::uuid() : null;
    }

    public static function record(int $event, ?string $key, int $position, int $count, string $frequency): void
    {
        if ($key === null) {
            return;
        }
        DB::table('event_series_occurrences')->insert([
            'event_id' => $event, 'series_key' => $key, 'position' => $position,
            'original_count' => $count, 'frequency' => $frequency,
        ]);
    }

    public static function forEvents(array $ids): array
    {
        if ($ids === [] || ! Schema::hasTable('event_series_occurrences')) {
            return [];
        }

        return DB::table('event_series_occurrences')->whereIn('event_id', $ids)->get()->keyBy('event_id')->all();
    }
}
