<?php

namespace App\Http\Controllers;

use App\Models\CommunityEvent;
use App\Models\CommunityThread;
use App\Models\Location;
use App\Models\User;
use App\Support\Audit;
use App\Support\CalendarExport;
use App\Support\Community;
use App\Support\EventDiscussions;
use App\Support\EventSeries;
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
        [$filters, $place, $query] = $this->publicSelection($request);
        $places = $site->isNetwork() ? Location::selectable()->orderBy('name')->get() : collect();
        $month = isset($filters['month']) ? CarbonImmutable::createFromFormat('!Y-m', $filters['month'], 'America/Winnipeg') : null;
        $calendar = [];
        $agenda = [];
        if ($month) {
            $nextMonth = $month->addMonth();
            $this->community->overlapping($query, $month, $nextMonth);
            foreach ((clone $query)->toBase()->select(['starts_at', 'ends_at', 'id', 'title', 'venue'])->orderBy('starts_at')->orderBy('id')->cursor() as $result) {
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

    private function publicSelection(Request $request): array
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
        $query = $this->community->upcoming($this->community->events($place?->id))
            ->when(filled($filters['q'] ?? null), fn ($query) => KeywordSearch::apply($query, $filters['q'], ['title', 'body', 'venue', 'organizer']))
            ->when($filters['category'] ?? null, fn ($query, $category) => $query->where('category', $category));

        return [$filters, $place, $query];
    }

    public function calendarSelection(Request $request)
    {
        [$filters, , $query] = $this->publicSelection($request);
        if (isset($filters['month'])) {
            $month = CarbonImmutable::createFromFormat('!Y-m', $filters['month'], 'America/Winnipeg');
            $this->community->overlapping($query, $month, $month->addMonth());
        }
        if (isset($filters['day'])) {
            $day = CarbonImmutable::createFromFormat('!Y-m-d', $filters['day'], 'America/Winnipeg');
            abort_if(isset($filters['month']) && $day->format('Y-m') !== $filters['month'], 422);
            $this->community->overlapping($query, $day, $day->addDay());
        }
        $events = $query->orderBy('starts_at')->orderBy('id')->limit(50)->get();

        return response(CalendarExport::events($events), 200, [
            'Content-Type' => 'text/calendar; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="canadamb-events.ics"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
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

        $filters = $request->validate(['reuse' => 'nullable|integer|min:1']);
        $source = isset($filters['reuse']) ? $this->community->events($site->location_id)
            ->where('author_id', $request->user()->id)->findOrFail($filters['reuse']) : null;

        return view('community.new-event', [
            'source' => $source,
            'places' => $site->isNetwork() ? Location::selectable()->orderBy('name')->get() : collect(),
            'suggestedLocation' => $source?->location ?? LocationScope::forBrowsing($site, $request->query('location', $request->cookie(LocationScope::PREFERENCE_COOKIE))),
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
        abort_unless($count === 1 || config('network.recurring_events'), 404);
        $data['location_id'] = LocationScope::forPublication($site, isset($data['location_id']) ? (int) $data['location_id'] : null);
        $starts = $this->community->localEventTime($data['starts_at'], 'starts_at');
        $ends = isset($data['ends_at']) ? $this->community->localEventTime($data['ends_at'], 'ends_at') : null;
        if ($starts->greaterThan(now('UTC')->addYear())) {
            throw ValidationException::withMessages(['starts_at' => 'Choose a date within the next year.']);
        }
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
                if (CommunityEvent::where('author_id', $author)->where('location_id', $data['location_id'])
                    ->where('title', $data['title'])->where('starts_at', $date['starts_at'])->whereIn('status', ['pending', 'approved'])->exists()) {
                    throw ValidationException::withMessages(['starts_at' => 'An occurrence already exists. No events were submitted.']);
                }
                $event = CommunityEvent::create($eventData + ['author_id' => $author]);
                Audit::record('community.event.submitted', $author, context: ['event' => $event->id]);
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
        $filters = $request->validate(['status' => ['nullable', Rule::in($statuses)], 'q' => 'nullable|string|max:100', 'sort' => 'nullable|in:upcoming,recent']);
        $owned = $this->community->events($site->location_id)->where('author_id', $request->user()->id);
        $counts = (clone $owned)->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
        $items = $owned->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when(filled($filters['q'] ?? null), fn ($query) => KeywordSearch::apply($query, $filters['q'], ['title', 'body', 'venue']))
            ->when(($filters['sort'] ?? null) === 'upcoming', fn ($query) => $query->where(fn ($dates) => $dates
                ->where('starts_at', '>=', now('UTC'))->orWhere('ends_at', '>=', now('UTC')))
                ->orderBy('starts_at')->orderBy('id'), fn ($query) => $query->orderByDesc('starts_at')->orderByDesc('id'))
            ->paginate(20)->withQueryString();

        $linked = EventDiscussions::linkedThreads($items->pluck('id')->all());

        return view('community.own-events', compact('items', 'statuses', 'filters', 'counts', 'linked'));
    }

    public function edit(Request $request, int $event)
    {
        $item = $this->ownedEvent($request)->findOrFail($event);
        $linkedThread = EventDiscussions::linkedThread($item);
        $this->ensureEditable($item, $linkedThread);

        return view('community.edit-event', compact('item', 'linkedThread'));
    }

    public function update(Request $request, int $event)
    {
        $this->ownedEvent($request)->findOrFail($event);
        $data = $request->validate([
            'title' => 'required|string|max:140', 'body' => 'required|string|min:10|max:5000',
            'category' => ['required', Rule::in(array_keys(Community::EVENTS))],
            'organizer' => 'required|string|max:150', 'venue' => 'required|string|max:180', 'event_url' => 'nullable|url:https|max:1024',
            'starts_at' => 'required|date_format:Y-m-d\\TH:i', 'ends_at' => 'nullable|date_format:Y-m-d\\TH:i',
            'version' => 'required|string|size:64', 'thread_version' => 'nullable|string|size:64',
        ]);
        $start = $this->community->localEventTime($data['starts_at'], 'starts_at');
        if ($start->isPast() || $start->greaterThan(now('UTC')->addYear())) {
            throw ValidationException::withMessages(['starts_at' => 'Choose an upcoming date within the next year.']);
        }
        $date = EventSeries::dates($this->community, $data['starts_at'], $data['ends_at'] ?? null, 'none', 1)[0];
        DB::transaction(function () use ($request, $event, $data, $date) {
            $author = $request->user()->id;
            User::lockForUpdate()->findOrFail($author);
            $item = $this->ownedEvent($request)->lockForUpdate()->findOrFail($event);
            $linkedThread = EventDiscussions::linkedThread($item, lock: true);
            $this->ensureEditable($item, $linkedThread);
            $this->checkVersion($item, $data['version']);
            if ($linkedThread && (! isset($data['thread_version']) || ! hash_equals($linkedThread->contentVersion(), $data['thread_version']))) {
                throw ValidationException::withMessages(['thread_version' => 'The linked discussion changed. Reload the event editor before saving.']);
            }
            $changes = array_replace(collect($data)->except(['version', 'thread_version'])->all(), $date, ['event_url' => $data['event_url'] ?? null]);
            $item->fill($changes);
            if (! $item->isDirty()) {
                return;
            }
            if (CommunityEvent::where('author_id', $author)->where('location_id', $item->location_id)
                ->whereKeyNot($item->id)->where('title', $item->title)->where('starts_at', $item->starts_at)
                ->whereIn('status', ['pending', 'approved'])->exists()) {
                throw ValidationException::withMessages(['starts_at' => 'An occurrence with this title and start time already exists.']);
            }
            if ($item->status !== 'pending' && config('network.recurring_events')
                && CommunityEvent::where('author_id', $author)->where('status', 'pending')->count() >= 24) {
                throw ValidationException::withMessages(['starts_at' => 'You can have up to 24 pending event submissions.']);
            }
            $item->forceFill(['status' => 'pending'])->save();
            Audit::record('community.event.edited', $author, context: ['event' => $event]);
            if ($linkedThread && ($linkedThread->title !== $item->title || $linkedThread->body !== $item->body)) {
                $linkedThread->forceFill(['title' => $item->title, 'body' => $item->body, 'status' => 'pending', 'pinned' => false])->save();
                Audit::record('community.thread.edited', $author, context: ['thread' => $linkedThread->id, 'event' => $event]);
            }
        }, attempts: 3);

        return redirect('/account/events')->with('status', 'Event saved. Changed details require moderation before publication.');
    }

    private function ownedEvent(Request $request)
    {
        $site = $request->attributes->get('site');
        abort_unless($this->community->enabled($site, 'events'), 404);

        return $this->community->events($site->location_id)->where('author_id', $request->user()->id);
    }

    private function ensureEditable(CommunityEvent $item, ?CommunityThread $linkedThread): void
    {
        abort_if(! in_array($item->status, ['pending', 'approved', 'rejected'], true) || $item->localStart()->isPast(), 409);
        abort_if($linkedThread && ! EventDiscussions::editable($item, $linkedThread), 409, 'The linked discussion cannot currently be edited.');
    }

    private function checkVersion(CommunityEvent $item, string $version): void
    {
        if (! hash_equals($item->contentVersion(), $version)) {
            throw ValidationException::withMessages(['version' => 'This event changed. Reload before continuing.']);
        }
    }

    public function cancel(Request $request, int $event)
    {
        $site = $request->attributes->get('site');
        abort_unless($this->community->enabled($site, 'events'), 404);
        $data = $request->validate(['cancellation_note' => 'nullable|string|max:300', 'version' => 'nullable|string|size:64']);
        DB::transaction(function () use ($event, $request, $site, $data) {
            $item = $this->community->events($site->location_id)->where('author_id', $request->user()->id)->lockForUpdate()->findOrFail($event);
            abort_unless(in_array($item->status, ['pending', 'approved'], true), 409);
            if (isset($data['version'])) {
                $this->checkVersion($item, $data['version']);
            }
            $this->cancelItem($item, $request->user()->id, $data['cancellation_note'] ?? null);
        });

        return redirect('/account/events')->with('status', 'Event cancellation recorded.');
    }

    public function reviseSelected(Request $request)
    {
        abort_unless(config('network.recurring_events'), 404);
        $data = $request->validate([
            'events' => 'required|array|list|min:1|max:8', 'events.*' => 'required|integer|distinct|min:1',
            'versions' => 'required|array|max:20', 'versions.*' => 'required|string|size:64',
            'field' => 'required|in:venue,organizer,event_url',
            'value' => ['required_unless:field,event_url', 'nullable', 'string', 'max:1024'],
            'confirm_update' => 'accepted',
        ]);
        $field = $data['field'];
        $value = $data['value'] ?? null;
        $request->validate(['value' => match ($field) {
            'venue' => 'required|string|max:180',
            'organizer' => 'required|string|max:150',
            default => 'nullable|url:https|max:1024',
        }]);
        $author = $request->user()->id;
        $changed = DB::transaction(function () use ($request, $data, $field, $value, $author) {
            User::lockForUpdate()->findOrFail($author);
            $items = $this->ownedEvent($request)->whereIn('id', $data['events'])->orderBy('id')->lockForUpdate()->get();
            abort_unless($items->count() === count($data['events']), 404);
            foreach ($items as $item) {
                abort_unless(in_array($item->status, ['pending', 'approved', 'rejected'], true) && ! $item->localStart()->isPast(), 409);
                if (! isset($data['versions'][$item->id])) {
                    throw ValidationException::withMessages(['versions' => 'Reload the event list before updating selected events.']);
                }
                $this->checkVersion($item, $data['versions'][$item->id]);
                $item->fill([$field => $value]);
            }
            $revisions = $items->filter(fn ($item) => $item->isDirty());
            $additional = $revisions->where('status', '!=', 'pending')->count();
            if ($additional && CommunityEvent::where('author_id', $author)->where('status', 'pending')->count() + $additional > 24) {
                throw ValidationException::withMessages(['events' => 'You can have up to 24 pending event submissions. No events changed.']);
            }
            foreach ($revisions as $item) {
                $item->forceFill(['status' => 'pending'])->save();
                Audit::record('community.event.edited', $author, context: ['event' => $item->id]);
            }

            return $revisions->count();
        }, attempts: 3);

        return redirect('/account/events')->with('status', $changed.' selected events updated. Changed events require moderation.');
    }

    public function shiftSelected(Request $request)
    {
        abort_unless(config('network.recurring_events'), 404);
        $data = $request->validate([
            'events' => 'required|array|list|min:1|max:8', 'events.*' => 'required|integer|distinct|min:1',
            'versions' => 'required|array|max:20', 'versions.*' => 'required|string|size:64',
            'days' => 'required|integer|between:-30,30|not_in:0', 'confirm_shift' => 'accepted',
        ]);
        $author = $request->user()->id;
        DB::transaction(function () use ($request, $data, $author) {
            User::lockForUpdate()->findOrFail($author);
            $items = $this->ownedEvent($request)->whereIn('id', $data['events'])->orderBy('id')->lockForUpdate()->get();
            abort_unless($items->count() === count($data['events']), 404);
            $proposed = [];
            foreach ($items as $item) {
                abort_unless(in_array($item->status, ['pending', 'approved', 'rejected'], true) && ! $item->localStart()->isPast(), 409);
                if (! isset($data['versions'][$item->id])) {
                    throw ValidationException::withMessages(['versions' => 'Reload the event list before rescheduling.']);
                }
                $this->checkVersion($item, $data['versions'][$item->id]);
                $dates = EventSeries::shift($this->community, $item->localStart()->format('Y-m-d\TH:i'), $item->localEnd()?->format('Y-m-d\TH:i'), (int) $data['days']);
                $key = json_encode([$item->location_id, mb_strtolower($item->title), $dates['starts_at']], JSON_THROW_ON_ERROR);
                if (isset($proposed[$key]) || CommunityEvent::where('author_id', $author)->where('location_id', $item->location_id)
                    ->whereNotIn('id', $items->modelKeys())->where('title', $item->title)->where('starts_at', $dates['starts_at'])
                    ->whereIn('status', ['pending', 'approved'])->exists()) {
                    throw ValidationException::withMessages(['days' => 'A revised occurrence would duplicate another event. No events changed.']);
                }
                $proposed[$key] = true;
                $item->fill($dates);
            }
            if (CommunityEvent::where('author_id', $author)->where('status', 'pending')->count() + $items->where('status', '!=', 'pending')->count() > 24) {
                throw ValidationException::withMessages(['events' => 'You can have up to 24 pending event submissions. No events changed.']);
            }
            foreach ($items as $item) {
                $item->forceFill(['status' => 'pending'])->save();
                Audit::record('community.event.edited', $author, context: ['event' => $item->id]);
            }
        }, attempts: 3);

        return redirect('/account/events')->with('status', count($data['events']).' selected events rescheduled for review.');
    }

    public function cancelSelected(Request $request)
    {
        $site = $request->attributes->get('site');
        abort_unless($this->community->enabled($site, 'events'), 404);
        $data = $request->validate([
            'events' => 'required|array|list|min:1|max:8', 'events.*' => 'required|integer|distinct|min:1',
            'confirm_cancel' => 'accepted', 'cancellation_note' => 'nullable|string|max:300',
            'versions' => 'sometimes|array|max:20', 'versions.*' => 'required|string|size:64',
        ]);
        $author = $request->user()->id;
        DB::transaction(function () use ($site, $data, $author) {
            $items = $this->community->events($site->location_id)->where('author_id', $author)
                ->whereIn('id', $data['events'])->orderBy('id')->lockForUpdate()->get();
            abort_unless($items->count() === count($data['events']), 404);
            foreach ($items as $item) {
                abort_unless(in_array($item->status, ['pending', 'approved'], true), 409);
                abort_if(($item->localEnd() ?? $item->localStart())->isPast(), 409, 'Select only upcoming or ongoing events.');
                if (isset($data['versions'])) {
                    if (! isset($data['versions'][$item->id])) {
                        throw ValidationException::withMessages(['versions' => 'Reload the event list before cancelling selected events.']);
                    }
                    $this->checkVersion($item, $data['versions'][$item->id]);
                }
            }
            foreach ($items as $item) {
                $this->cancelItem($item, $author, $data['cancellation_note'] ?? null);
            }
        }, attempts: 3);

        return redirect('/account/events')->with('status', count($data['events']).' selected event cancellations recorded.');
    }

    private function cancelItem(CommunityEvent $item, int $author, ?string $note): void
    {
        $status = $item->status === 'approved' ? 'cancelled' : 'withdrawn';
        $item->forceFill(['status' => $status, 'cancelled_at' => now(), 'cancellation_note' => $note])->save();
        Audit::record('community.event.'.$status, $author, context: ['event' => $item->id]);
    }
}
