<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final class EventSeries
{
    public static function shift(Community $community, string $start, ?string $end, int $days): array
    {
        // Shift the local calendar date, not a fixed number of UTC seconds.
        $shift = fn (string $value) => CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $value, 'UTC')->addDays($days)->format('Y-m-d\TH:i');
        $dates = self::dates($community, $shift($start), $end === null ? null : $shift($end), 'none', 1)[0];
        $utc = CarbonImmutable::parse($dates['starts_at'], 'UTC');
        if ($utc->isPast() || $utc->greaterThan(now('UTC')->addYear())) {
            throw ValidationException::withMessages(['days' => 'Every revised occurrence must be upcoming and within the next year.']);
        }

        return $dates;
    }

    public static function dates(Community $community, string $start, ?string $end, string $frequency, int $count): array
    {
        $dates = [];
        $weeks = $frequency === 'biweekly' ? 2 : 1;
        for ($i = 0; $i < $count; $i++) {
            // Add weeks to a timezone-free wall clock, then validate the
            // resulting local time rather than silently shifting DST gaps.
            $wallStart = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $start, 'UTC')->addWeeks($i * $weeks)->format('Y-m-d\TH:i');
            $utcStart = $community->localEventTime($wallStart, 'starts_at');
            $utcEnd = null;
            if ($end !== null) {
                $wallEnd = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $end, 'UTC')->addWeeks($i * $weeks)->format('Y-m-d\TH:i');
                $utcEnd = $community->localEventTime($wallEnd, 'ends_at');
            }
            if ($utcEnd && $utcEnd->lessThanOrEqualTo($utcStart)) {
                throw ValidationException::withMessages(['ends_at' => 'Every occurrence must end after it starts.']);
            }
            if ($count > 1 && $utcStart->greaterThan(now('UTC')->addYear())) {
                throw ValidationException::withMessages(['starts_at' => 'All recurring occurrences must fall within the next year.']);
            }
            $dates[] = ['starts_at' => $utcStart->format('Y-m-d H:i:s'), 'ends_at' => $utcEnd?->format('Y-m-d H:i:s')];
        }

        return $dates;
    }
}
