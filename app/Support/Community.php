<?php

namespace App\Support;

use App\Models\CommunityEvent;
use App\Models\CommunityThread;
use App\Models\Site;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class Community
{
    public const FORUMS = ['general' => 'General discussion', 'recommendations' => 'Recommendations', 'help' => 'Questions and help',
        'lost-and-found' => 'Lost and found', 'local-issues' => 'Local issues', 'interests' => 'Hobbies and interests'];

    public const EVENTS = ['community' => 'Community', 'arts' => 'Arts and culture', 'sports' => 'Sports and recreation',
        'markets' => 'Markets and festivals', 'learning' => 'Learning', 'family' => 'Family'];

    public function enabled(Site $site, string $module): bool
    {
        return app(NetworkSettings::class)->forSite($site)['modules'][$module] ?? false;
    }

    public function threads(?int $location): Builder
    {
        return CommunityThread::where('status', 'approved')->with(['location', 'author'])
            ->withCount(['replies' => fn (Builder $query) => $query->where('status', 'approved')])
            ->when($location, fn (Builder $query, int $id) => $query->where('location_id', $id));
    }

    public function events(?int $location): Builder
    {
        return CommunityEvent::with('location')->when($location, fn (Builder $query, int $id) => $query->where('location_id', $id));
    }

    public function upcoming(Builder $events): Builder
    {
        $now = now('UTC')->format('Y-m-d H:i:s');

        return $events->where('status', 'approved')->where(fn (Builder $query) => $query->where('starts_at', '>=', $now)->orWhere('ends_at', '>=', $now));
    }

    public function overlapping(Builder $events, CarbonImmutable $from, CarbonImmutable $until): Builder
    {
        $start = $from->utc()->format('Y-m-d H:i:s');
        $end = $until->utc()->format('Y-m-d H:i:s');

        return $events->where('starts_at', '<', $end)
            ->where(fn (Builder $query) => $query->where('ends_at', '>', $start)
                ->orWhere(fn (Builder $instant) => $instant->whereNull('ends_at')->where('starts_at', '>=', $start)));
    }

    public function canonicalSite(CommunityThread|CommunityEvent $item, string $module): Site
    {
        $item->loadMissing('location.site.domain');
        $local = $item->location?->site;

        return $local?->status === 'active' && $this->enabled($local, $module) ? $local : NetworkUrl::main();
    }

    public function localEventTime(string $value, string $field): CarbonImmutable
    {
        $zone = new DateTimeZone('America/Winnipeg');
        $wall = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $value, $zone);
        if ($wall->format('Y-m-d\TH:i') !== $value) {
            throw ValidationException::withMessages([$field => 'Choose a Manitoba time outside the daylight-saving clock change.']);
        }
        $timestamp = $wall->getTimestamp();
        foreach ([-3600, 3600] as $seconds) {
            if ($wall->setTimestamp($timestamp + $seconds)->format('Y-m-d\TH:i') === $value) {
                throw ValidationException::withMessages([$field => 'Choose an unambiguous time outside the daylight-saving clock change.']);
            }
        }

        $utc = $wall->utc();
        if ($utc->year < 1000 || $utc->year > 9999) {
            throw ValidationException::withMessages([$field => 'Choose a date supported by the event calendar.']);
        }

        return $utc;
    }
}
