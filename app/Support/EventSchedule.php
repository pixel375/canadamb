<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class EventSchedule
{
    public static function available(): bool
    {
        return (bool) config('network.event_date_only') && Schema::hasColumn('community_events', 'date_only');
    }

    public static function normalize(Request $request): void
    {
        if (! $request->exists('starts_on')) {
            return;
        }
        abort_unless(self::available(), 404);
        $fields = $request->validate([
            'starts_on' => 'required|date_format:Y-m-d',
            'start_time' => 'nullable|date_format:H:i',
            'ends_on' => 'nullable|date_format:Y-m-d',
            'end_time' => 'nullable|date_format:H:i',
            'starts_at' => 'prohibited',
            'ends_at' => 'prohibited',
        ]);
        $timed = isset($fields['start_time']);
        if ((! $timed && isset($fields['end_time'])) || ($timed && isset($fields['ends_on']) && ! isset($fields['end_time']))) {
            throw ValidationException::withMessages(['ends_at' => 'For a timed event enter an end time, or leave both end fields blank. Date-only events must not have an end time.']);
        }
        $endDate = $fields['ends_on'] ?? $fields['starts_on'];
        $request->merge([
            'starts_at' => $fields['starts_on'].($timed ? 'T'.$fields['start_time'] : ''),
            'ends_at' => $timed ? (isset($fields['end_time']) ? $endDate.'T'.$fields['end_time'] : null) : ($fields['ends_on'] ?? null),
        ]);
    }

    public static function rules(): array
    {
        $format = self::available() ? 'Y-m-d\\TH:i,Y-m-d' : 'Y-m-d\\TH:i';

        return ['starts_at' => 'required|date_format:'.$format, 'ends_at' => 'nullable|date_format:'.$format];
    }

    public static function bounds(Community $community, string $start, ?string $end): array
    {
        $dateOnly = strlen($start) === 10;
        if ($dateOnly && ! self::available()) {
            throw ValidationException::withMessages(['starts_at' => 'Date-only events are not available.']);
        }
        if ($end !== null && (strlen($end) === 10) !== $dateOnly) {
            throw ValidationException::withMessages(['ends_at' => 'Start and end must both be dates or both include times.']);
        }
        if ($dateOnly) {
            if ($end !== null && $end < $start) {
                throw ValidationException::withMessages(['ends_at' => 'The last event date cannot precede the first.']);
            }
            $first = $community->localEventTime($start.'T00:00', 'starts_at');
            $last = CarbonImmutable::createFromFormat('!Y-m-d', $end ?? $start, 'America/Winnipeg')->addDay();
            if ($last->year > 9999) {
                throw ValidationException::withMessages(['ends_at' => 'Choose a supported last event date.']);
            }
            $until = $last->utc();
            if ($last->diffInDays($first->setTimezone('America/Winnipeg'), absolute: true) > 366) {
                throw ValidationException::withMessages(['ends_at' => 'Choose a date range of at most one year.']);
            }
        } else {
            $first = $community->localEventTime($start, 'starts_at');
            $until = $end !== null ? $community->localEventTime($end, 'ends_at') : null;
            if ($until && $until->lessThanOrEqualTo($first)) {
                throw ValidationException::withMessages(['ends_at' => 'Every occurrence must end after it starts.']);
            }
        }
        $result = ['starts_at' => $first->format('Y-m-d H:i:s'), 'ends_at' => $until?->format('Y-m-d H:i:s')];
        if (self::available()) {
            $result['date_only'] = $dateOnly;
        }

        return $result;
    }

    public static function upcoming(array $bounds): bool
    {
        $start = CarbonImmutable::parse($bounds['starts_at'], 'UTC');
        $earliest = ! empty($bounds['date_only']) ? now('America/Winnipeg')->startOfDay()->utc() : now('UTC');

        return $start->greaterThanOrEqualTo($earliest) && $start->lessThanOrEqualTo(now('UTC')->addYear());
    }
}
