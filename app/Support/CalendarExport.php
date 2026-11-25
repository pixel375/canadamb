<?php

namespace App\Support;

use App\Models\CommunityEvent;
use Carbon\CarbonImmutable;

final class CalendarExport
{
    public static function event(CommunityEvent $event): string
    {
        $lines = [
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//CanadaMB//Community Events//EN', 'CALSCALE:GREGORIAN',
            'BEGIN:VEVENT', 'UID:event-'.$event->id.'@canadamb.ca',
            'DTSTAMP:'.CarbonImmutable::parse($event->updated_at, 'UTC')->utc()->format('Ymd\THis\Z'),
            'DTSTART:'.$event->localStart()->utc()->format('Ymd\THis\Z'),
        ];
        if ($event->localEnd()) {
            $lines[] = 'DTEND:'.$event->localEnd()->utc()->format('Ymd\THis\Z');
        }
        $lines = array_merge($lines, [
            'SUMMARY:'.self::escape($event->title),
            'DESCRIPTION:'.self::escape($event->body),
            'LOCATION:'.self::escape($event->venue.', '.$event->location->name.', Manitoba'),
            'STATUS:CONFIRMED', 'END:VEVENT', 'END:VCALENDAR',
        ]);

        return implode("\r\n", array_map(self::fold(...), $lines))."\r\n";
    }

    private static function escape(string $value): string
    {
        return str_replace(["\\", "\r\n", "\r", "\n", ';', ','], ["\\\\", '\\n', '\\n', '\\n', '\\;', '\\,'], $value);
    }

    private static function fold(string $line): string
    {
        $parts = [];
        $limit = 75;
        while (strlen($line) > $limit) {
            $part = mb_strcut($line, 0, $limit, 'UTF-8');
            $parts[] = $part;
            $line = substr($line, strlen($part));
            $limit = 74;
        }
        $parts[] = $line;

        return implode("\r\n ", $parts);
    }
}
