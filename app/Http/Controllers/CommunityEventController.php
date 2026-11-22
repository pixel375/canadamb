<?php

namespace App\Http\Controllers;

use App\Models\CommunityEvent;
use App\Models\Location;
use App\Support\ApprovalPolicy;
use App\Support\Community;
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
            'day' => 'nullable|date_format:Y-m-d',
            'q' => 'nullable|string|max:100', 'category' => ['nullable', Rule::in(array_keys(Community::EVENTS))],
        ]);
        $month = isset($filters['month']) ? CarbonImmutable::createFromFormat('!Y-m', $filters['month'], 'America/Winnipeg') : null;
        $query = $this->community->upcoming($this->community->events($site->location_id))
            ->when($filters['q'] ?? null, fn ($query, $term) => $query->whereLike('title', '%'.$term.'%'))
            ->when($filters['category'] ?? null, fn ($query, $category) => $query->where('category', $category));
        $calendar = [];
        if ($month) {
            $nextMonth = $month->addMonth();
            $this->community->overlapping($query, $month, $nextMonth);
            foreach ((clone $query)->toBase()->select(['starts_at', 'ends_at'])->cursor() as $result) {
                $start = CarbonImmutable::parse($result->starts_at, 'UTC')->setTimezone('America/Winnipeg')->startOfDay();
                $last = $result->ends_at
                    ? CarbonImmutable::parse($result->ends_at, 'UTC')->subSecond()->setTimezone('America/Winnipeg')->startOfDay()
                    : $start;
                for ($day = $start->max($month); $day->lt($nextMonth) && $day->lte($last); $day = $day->addDay()) {
                    $date = $day->toDateString();
                    $calendar[$date] = ($calendar[$date] ?? 0) + 1;
                }
            }
        }
        if (isset($filters['day'])) {
            $day = CarbonImmutable::createFromFormat('!Y-m-d', $filters['day'], 'America/Winnipeg');
            abort_if($month && $day->format('Y-m') !== $month->format('Y-m'), 422);
            $this->community->overlapping($query, $day, $day->addDay());
        }
        $items = $query->orderBy('starts_at')->orderBy('id')->paginate(24)->withQueryString();

        return view('community.events', compact('items', 'filters', 'month', 'calendar'));
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

        return view('community.event', compact('item', 'canonical'));
    }

    public function create(Request $request)
    {
        $site = $request->attributes->get('site');
        abort_unless($this->community->enabled($site, 'events'), 404);

        return view('community.new-event', [
            'places' => $site->isNetwork() ? Location::selectable()->orderBy('name')->get() : collect(),
        ]);
    }

    public function store(Request $request, ApprovalPolicy $policy)
    {
        $site = $request->attributes->get('site');
        abort_unless($this->community->enabled($site, 'events'), 404);
        $data = $request->validate([
            'title' => 'required|string|max:140', 'body' => 'required|string|min:10|max:5000',
            'category' => ['required', Rule::in(array_keys(Community::EVENTS))], 'location_id' => 'nullable|integer',
            'organizer' => 'required|string|max:150', 'venue' => 'required|string|max:180', 'event_url' => 'nullable|url:https|max:1024',
            'starts_at' => 'required|date_format:Y-m-d\\TH:i', 'ends_at' => 'nullable|date_format:Y-m-d\\TH:i',
        ]);
        $data['location_id'] = LocationScope::forPublication($site, isset($data['location_id']) ? (int) $data['location_id'] : null);
        $starts = $this->community->localEventTime($data['starts_at'], 'starts_at');
        $ends = isset($data['ends_at']) ? $this->community->localEventTime($data['ends_at'], 'ends_at') : null;
        if ($starts->isPast()) {
            throw ValidationException::withMessages(['starts_at' => 'Choose an upcoming date and time.']);
        }
        if ($ends && $ends->lessThanOrEqualTo($starts)) {
            throw ValidationException::withMessages(['ends_at' => 'The end time must come after the start time.']);
        }
        $data['starts_at'] = $starts->format('Y-m-d H:i:s');
        $data['ends_at'] = $ends?->format('Y-m-d H:i:s');
        $approved = DB::transaction(function () use ($request, $site, $data, $policy, $starts, $ends) {
            $author = $request->user()->id;
            $item = new CommunityEvent($data + ['author_id' => $author]);
            $revision = $policy->automaticRevision($site, 'event', $item->location_id);
            $approved = $revision !== null && ! ($ends ?? $starts)->isPast();
            if ($approved) {
                $item->forceFill(['status' => 'approved']);
            }
            $item->save();
            if ($approved) {
                $policy->recordAutomaticApproval('event', $item->id, $author, $site, $revision, $item->location_id);
            }

            return $approved;
        }, attempts: 3);

        return redirect('/account/events')->with('status', $approved ? 'Event published.' : 'Event submitted for review.');
    }

    public function owned(Request $request)
    {
        $site = $request->attributes->get('site');
        $items = $this->community->events($site->location_id)->where('author_id', $request->user()->id)
            ->orderByDesc('starts_at')->paginate(20);

        return view('community.own-events', compact('items'));
    }

    public function cancel(Request $request, int $event)
    {
        $site = $request->attributes->get('site');
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
