<?php

namespace App\Http\Controllers;

use App\Models\Location;
use App\Support\Community;
use App\Support\NetworkUrl;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
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
            'month' => ['nullable', 'date_format:Y-m', 'regex:/^[1-9][0-9]{3}-[0-9]{2}$/'],
            'day' => ['nullable', 'date_format:Y-m-d', 'regex:/^[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}$/'],
            'q' => 'nullable|string|max:100', 'category' => ['nullable', Rule::in(array_keys(Community::EVENTS))],
            'location' => $site->isNetwork()
                ? ['nullable', 'integer', Rule::exists('locations', 'id')->where('province', 'MB')->where('selectable', true)]
                : ['prohibited'],
            'page' => 'nullable|integer|min:1|max:100000',
        ]);
        $today = CarbonImmutable::today('America/Winnipeg');
        $selectedDay = isset($filters['day']) ? CarbonImmutable::createFromFormat('!Y-m-d', $filters['day'], 'America/Winnipeg') : null;
        $month = isset($filters['month']) ? CarbonImmutable::createFromFormat('!Y-m', $filters['month'], 'America/Winnipeg')
            : ($selectedDay ?? $today)->startOfMonth();
        abort_if($selectedDay && $selectedDay->format('Y-m') !== $month->format('Y-m'), 422);
        $from = $selectedDay ?? (isset($filters['month']) ? $month : $today);
        $until = $selectedDay ? $selectedDay->addDay() : $month->addMonth();

        $query = $this->community->publicEvents($site, isset($filters['location']) ? (int) $filters['location'] : null)
            ->where(fn ($events) => $events->where('status', 'approved')->orWhereNotNull('thread_id'))
            ->when(isset($filters['q']), function ($query) use ($filters) {
                $term = strtr($filters['q'], ['!' => '!!', '%' => '!%', '_' => '!_']);
                $query->whereRaw("title LIKE ? ESCAPE '!'", ["%$term%"]);
            })
            ->when($filters['category'] ?? null, fn ($query, $category) => $query->where('category', $category));
        $this->community->overlapping($query, $from, $until);
        $page = (int) ($filters['page'] ?? 1);
        $limit = $selectedDay ? 24 : 8;
        $skip = $selectedDay ? ($page - 1) * $limit : 0;
        $calendar = [];
        // A single ordered statement drives the counts and cards, even when a cancellation commits mid-request.
        foreach ($query->withoutEagerLoads()->join('locations as place', 'place.id', '=', 'community_events.location_id')
            ->select('community_events.id', 'thread_id', 'title', 'category', 'venue', 'starts_at', 'ends_at', 'status', 'place.name as place_name')
            ->orderBy('starts_at')->orderBy('community_events.id')->cursor() as $item) {
            $first = $item->localStart()->startOfDay();
            $last = $item->localEnd()?->subSecond()->startOfDay() ?? $first;
            for ($day = $first->max($from); $day->lt($until) && $day->lte($last); $day = $day->addDay()) {
                $date = $day->toDateString();
                $calendar[$date] ??= ['day' => $day, 'count' => 0, 'events' => []];
                $position = $calendar[$date]['count']++;
                if ($position >= $skip && $position < $skip + $limit) {
                    $calendar[$date]['events'][] = $item;
                }
            }
        }
        ksort($calendar);
        $pagination = $selectedDay ? new LengthAwarePaginator([], ($calendar[$selectedDay->toDateString()]['count'] ?? 0),
            $limit, $page, ['path' => '/events', 'query' => array_diff_key($filters, ['page' => true])]) : null;
        $places = $site->isNetwork() ? Location::selectable()->orderBy('name')->get(['id', 'name']) : collect();
        $canonical = NetworkUrl::to($site, '/events');

        return view('community.events', compact('calendar', 'filters', 'month', 'today', 'selectedDay', 'pagination', 'places', 'canonical'));
    }

    public function show(Request $request, int $event)
    {
        $site = $request->attributes->get('site');
        abort_unless($this->community->enabled($site, 'events'), 404);
        $item = $this->community->publicEvents($site)->findOrFail($event);
        if ($item->thread_id !== null) {
            return redirect($item->publicPath(), headers: ['Cache-Control' => 'no-store']);
        }
        $canonical = NetworkUrl::to($this->community->canonicalSite($item, 'events'), $item->publicPath());
        if (parse_url($canonical, PHP_URL_HOST) !== $site->domain->host) {
            return redirect()->away($canonical, 301, ['Cache-Control' => 'no-store']);
        }

        $view = view('community.event', compact('item', 'canonical'));

        return $item->status === 'cancelled' ? response($view)->header('X-Robots-Tag', 'noindex, follow') : $view;
    }

    public function create(Request $request)
    {
        $site = $request->attributes->get('site');
        abort_unless($this->community->enabled($site, 'events'), 404);

        return view('community.new-event', ['canRequest' => $this->community->enabled($site, 'community')]);
    }

    public function store(Request $request)
    {
        $site = $request->attributes->get('site');
        abort_unless($this->community->enabled($site, 'events'), 404);
        $message = $this->community->enabled($site, 'community')
            ? 'Request an event listing from your approved discussion.'
            : 'Event requests are unavailable on this site.';
        if ($request->expectsJson()) {
            throw ValidationException::withMessages(['event' => $message]);
        }

        return redirect('/events/new', 303)->with('status', $message);
    }
}
