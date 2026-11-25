<?php

namespace App\Http\Controllers;

use App\Models\CommunityEvent;
use App\Models\Location;
use App\Models\User;
use App\Support\CalendarExport;
use App\Support\Community;
use App\Support\EventSeries;
use App\Support\EventDiscussions;
use App\Support\KeywordSearch;
use App\Support\LocationScope;
use App\Support\NetworkUrl;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CommunityEventController extends Controller
{
    public function __construct(private readonly Community $community) {}

    public function index(Request $request)
    {
        $site = $request->attributes->get('site');
        abort_unless($this->community->enabled($site, 'events'), 404);
        $filters = $request->validate([
            'month' => 'nullable|date_format:Y-m',
            'location' => ['nullable', 'string', 'max:100', Rule::exists('locations', 'slug')->where('province', 'MB')->where('selectable', true)],
            'day' => 'nullable|date_format:Y-m-d',
            'q' => 'nullable|string|max:100', 'category' => ['nullable', Rule::in(array_keys(Community::EVENTS))],
        ]);
        $place = LocationScope::forBrowsing($site, $filters['location'] ?? null);
        $places = $site->isNetwork() ? Location::selectable()->orderBy('name')->get() : collect();
        $month = isset($filters['month']) ? CarbonImmutable::createFromFormat('!Y-m', $filters['month'], 'America/Winnipeg') : null;
        $query = $this->community->upcoming($this->community->events($place?->id))
            ->when($filters['q'] ?? null, fn ($query, $term) => KeywordSearch::apply($query, $term, ['title', 'body', 'venue', 'organizer']))
            ->when($filters['category'] ?? null, fn ($query, $category) => $query->where('category', $category));
        $calendar = [];
        $agenda = [];
        if ($month) {
            $nextMonth = $month->addMonth();
            $this->community->overlapping($query, $month, $nextMonth);
            foreach ((clone $query)->toBase()->select(['starts_at', 'ends_at', 'id', 'title', 'venue'])->cursor() as $result) {
                $start = CarbonImmutable::parse($result->starts_at, 'UTC')->setTimezone('America/Winnipeg')->startOfDay();
                $last = $result->ends_at
                    ? CarbonImmutable::parse($result->ends_at, 'UTC')->subSecond()->setTimezone('America/Winnipeg')->startOfDay()
                    : $start;
                for ($day = $start->max($month); $day->lt($nextMonth) && $day->lte($last); $day = $day->addDay()) {
                    $date = $day->toDateString();
                    $calendar[$date] = ($calendar[$date] ?? 0) + 1;
                    if (count($agenda[$date] ?? []) < 5) {
                        $agenda[$date][] = $result;
                    }
                }
            }
        }
        if (isset($filters['day'])) {
            $day = CarbonImmutable::createFromFormat('!Y-m-d', $filters['day'], 'America/Winnipeg');
            abort_if($month && $day->format('Y-m') !== $month->format('Y-m'), 422);
            $this->community->overlapping($query, $day, $day->addDay());
        }
        $items = $query->orderBy('starts_at')->orderBy('id')->paginate(24)->withQueryString();

        return view('community.events', compact('items', 'filters', 'month', 'calendar', 'agenda', 'place', 'places'));
    }

    public function show(Request $request, int $event)
    {
        $site = $request->attributes->get('site');
        abort_unless($this->community->enabled($site, 'events'), 404);
        $item = $this->community->events($site->location_id)->whereIn('status', ['approved', 'cancelled'])->findOrFail($event);
        $canonical = NetworkUrl::to($this->community->canonicalSite($item, 'events'), '/events/'.$item->id);
        if (parse_url($canonical, PHP_URL_HOST) !== $site->domain->host) {
            return redirect()->away($canonical, 301, ['Cache-Control' => 'no-store']);
        }

        $discussion = EventDiscussions::publicThread($site, $item);

        return view('community.event', compact('item', 'canonical', 'discussion'));
    }

    public function calendarFile(Request $request, int $event)
    {
        $site = $request->attributes->get('site');
        abort_unless($this->community->enabled($site, 'events'), 404);
        $item = $this->community->events($site->location_id)->where('status', 'approved')->findOrFail($event);

        return response(CalendarExport::event($item), 200, [
            'Content-Type' => 'text/calendar; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="canadamb-event-'.$item->id.'.ics"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function create(Request $request)
    {
        $site = $request->attributes->get('site');
        abort_unless($this->community->enabled($site, 'events'), 404);

        return view('community.new-event', [
            'places' => $site->isNetwork() ? Location::selectable()->orderBy('name')->get() : collect(),
            'suggestedLocation' => LocationScope::forBrowsing($site, $request->query('location', $request->cookie(LocationScope::PREFERENCE_COOKIE))),
        ]);
    }

    public function store(Request $request)
    {
        $site = $request->attributes->get('site');
        abort_unless($this->community->enabled($site, 'events'), 404);
        $data = $request->validate([
            'title' => 'required|string|max:140', 'body' => 'required|string|min:10|max:5000',
            'category' => ['required', Rule::in(array_keys(Community::EVENTS))], 'location_id' => 'nullable|integer',
            'organizer' => 'required|string|max:150', 'venue' => 'required|string|max:180', 'event_url' => 'nullable|url:https|max:1024',
            'starts_at' => 'required|date_format:Y-m-d\\TH:i', 'ends_at' => 'nullable|date_format:Y-m-d\\TH:i',
        ]);
        $repeat = $request->validate([
            'repeat' => 'nullable|in:none,weekly,biweekly',
            'occurrences' => 'nullable|integer|min:2|max:8',
        ]);
        $frequency = $repeat['repeat'] ?? 'none';
        $count = $frequency === 'none' ? 1 : (int) ($repeat['occurrences'] ?? 2);
        abort_if($count > 1 && ! config('network.recurring_events'), 404);
        $data['location_id'] = LocationScope::forPublication($site, isset($data['location_id']) ? (int) $data['location_id'] : null);
        $starts = $this->community->localEventTime($data['starts_at'], 'starts_at');
        $ends = isset($data['ends_at']) ? $this->community->localEventTime($data['ends_at'], 'ends_at') : null;
        if ($starts->isPast()) {
            throw ValidationException::withMessages(['starts_at' => 'Choose an upcoming date and time.']);
        }
        if ($ends && $ends->lessThanOrEqualTo($starts)) {
            throw ValidationException::withMessages(['ends_at' => 'The end time must come after the start time.']);
        }
        $dates = EventSeries::dates($this->community, $data['starts_at'], $data['ends_at'] ?? null, $frequency, $count);
        DB::transaction(function () use ($request, $site, $data, $dates, $count) {
            $author = $request->user()->id;
            User::lockForUpdate()->findOrFail($author);
            if (config('network.recurring_events')) {
                $pending = CommunityEvent::where('author_id', $author)->where('status', 'pending')->count();
                if ($pending + $count > 24) {
                    throw ValidationException::withMessages(['starts_at' => 'You can have up to 24 pending event submissions.']);
                }
            }
            foreach ($dates as $date) {
                $eventData = array_replace($data, $date);
                if ($count > 1 && CommunityEvent::where('author_id', $author)->where('location_id', $data['location_id'])
                    ->where('title', $data['title'])->where('starts_at', $date['starts_at'])->whereIn('status', ['pending', 'approved'])->exists()) {
                    throw ValidationException::withMessages(['starts_at' => 'An occurrence already exists. No events were submitted.']);
                }
                $event = CommunityEvent::create($eventData + ['author_id' => $author]);
                if (EventDiscussions::enabled() && $this->community->enabled($site, 'community')) {
                    EventDiscussions::create($event);
                }
            }
        }, attempts: 3);

        return redirect('/account/events')->with('status', $count > 1 ? $count.' event occurrences submitted for individual review.' : 'Event submitted for review.');
    }

    public function owned(Request $request)
    {
        $site = $request->attributes->get('site');
        abort_unless($this->community->enabled($site, 'events'), 404);
        $statuses = ['pending', 'approved', 'rejected', 'cancelled', 'withdrawn'];
        $filters = $request->validate(['status' => ['nullable', Rule::in($statuses)], 'q' => 'nullable|string|max:100']);
        $owned = $this->community->events($site->location_id)->where('author_id', $request->user()->id);
        $counts = (clone $owned)->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
        $items = $owned->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['q'] ?? null, fn ($query, $term) => KeywordSearch::apply($query, $term, ['title', 'body', 'venue']))
            ->orderByDesc('starts_at')->orderByDesc('id')->paginate(20)->withQueryString();

        return view('community.own-events', compact('items', 'statuses', 'filters', 'counts'));
    }

    public function cancel(Request $request, int $event)
    {
        $site = $request->attributes->get('site');
        abort_unless($this->community->enabled($site, 'events'), 404);
        $data = $request->validate(['cancellation_note' => 'nullable|string|max:300']);
        DB::transaction(function () use ($event, $request, $site, $data) {
            $item = $this->community->events($site->location_id)->where('author_id', $request->user()->id)->lockForUpdate()->findOrFail($event);
            abort_unless(in_array($item->status, ['pending', 'approved'], true), 409);
            $item->forceFill([
                'status' => $item->status === 'approved' ? 'cancelled' : 'withdrawn',
                'cancelled_at' => now(), 'cancellation_note' => $data['cancellation_note'] ?? null,
            ])->save();
        });

        return redirect('/account/events')->with('status', 'Event cancelled.');
    }
}
