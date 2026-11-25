<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final class EventSeries
{
    public static function dates(Community $community, string $start, ?string $end, string $frequency, int $count): array
    {
        $localStart = $community->localEventTime($start, 'starts_at')->setTimezone('America/Winnipeg');
        $localEnd = $end ? $community->localEventTime($end, 'ends_at')->setTimezone('America/Winnipeg') : null;
        $dates = [];
        $weeks = $frequency === 'biweekly' ? 2 : 1;
        for ($i = 0; $i < $count; $i++) {
            // Add weeks to a timezone-free wall clock, then validate the
            // resulting local time rather than silently shifting DST gaps.
            $wallStart = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $start, 'UTC')->addWeeks($i * $weeks)->format('Y-m-d\TH:i');
            $utcStart = $community->localEventTime($wallStart, 'starts_at');
            $utcEnd = null;
            if ($localEnd) {
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
