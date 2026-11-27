<?php

namespace App\Support;

use App\Models\CommunityEvent;
use Carbon\CarbonImmutable;

final class CalendarExport
{
    public static function event(CommunityEvent $event): string
    {
        return self::events([$event]);
    }

    public static function events(iterable $events): string
    {
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//CanadaMB//Community Events//EN', 'CALSCALE:GREGORIAN'];
        foreach ($events as $event) {
            $lines = array_merge($lines, self::eventLines($event));
        }
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map(self::fold(...), $lines))."\r\n";
    }

    private static function eventLines(CommunityEvent $event): array
    {
        $lines = [
            'BEGIN:VEVENT', 'UID:event-'.$event->id.'@canadamb.ca',
            'DTSTAMP:'.CarbonImmutable::parse($event->updated_at, 'UTC')->utc()->format('Ymd\THis\Z'),
        ];
        if ($event->isDateOnly()) {
            $lines[] = 'DTSTART;VALUE=DATE:'.$event->localStart()->format('Ymd');
            $lines[] = 'DTEND;VALUE=DATE:'.$event->localEnd()->format('Ymd');
        } else {
            $lines[] = 'DTSTART:'.$event->localStart()->utc()->format('Ymd\THis\Z');
            if ($event->localEnd()) {
                $lines[] = 'DTEND:'.$event->localEnd()->utc()->format('Ymd\THis\Z');
            }
        }
        $lines = array_merge($lines, [
            'SUMMARY:'.self::escape($event->title),
            'DESCRIPTION:'.self::escape($event->body),
            'LOCATION:'.self::escape($event->venue.', '.$event->location->name.', Manitoba'),
            'STATUS:CONFIRMED', 'END:VEVENT',
        ]);

        return $lines;
    }

    private static function escape(string $value): string
    {
        return str_replace([chr(92), "\r\n", "\r", "\n", ';', ','], [str_repeat(chr(92), 2), '\\n', '\\n', '\\n', '\\;', '\\,'], $value);
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
