<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final class EventSeries
{
    private static function move(string $value, int $days): string
    {
        $format = strlen($value) === 10 ? 'Y-m-d' : 'Y-m-d\\TH:i';

        return CarbonImmutable::createFromFormat('!'.$format, $value, 'UTC')->addDays($days)->format($format);
    }

    public static function shift(Community $community, string $start, ?string $end, int $days): array
    {
        $dates = self::dates($community, self::move($start, $days), $end === null ? null : self::move($end, $days), 'none', 1)[0];
        if (! EventSchedule::upcoming($dates)) {
            throw ValidationException::withMessages(['days' => 'Every revised occurrence must be upcoming and within the next year.']);
        }

        return $dates;
    }

    public static function dates(Community $community, string $start, ?string $end, string $frequency, int $count): array
    {
        $dates = [];
        $weeks = $frequency === 'biweekly' ? 2 : 1;
        for ($i = 0; $i < $count; $i++) {
            // Move local calendar dates rather than fixed UTC seconds.
            $wallStart = self::move($start, $i * $weeks * 7);
            $wallEnd = $end === null ? null : self::move($end, $i * $weeks * 7);
            $bounds = EventSchedule::bounds($community, $wallStart, $wallEnd);
            if ($count > 1 && ! EventSchedule::upcoming($bounds)) {
                throw ValidationException::withMessages(['starts_at' => 'All recurring occurrences must fall within the next year.']);
            }
            $dates[] = $bounds;
        }

        return $dates;
    }
}
