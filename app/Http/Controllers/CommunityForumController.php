<?php

namespace App\Http\Controllers;

use App\Models\CommunityEvent;
use App\Models\CommunityThread;
use App\Models\Location;
use App\Support\Community;
use App\Support\KeywordSearch;
use App\Support\LocationScope;
use App\Support\NetworkUrl;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CommunityForumController extends Controller
{
    public function __construct(private readonly Community $community) {}

    public function index(Request $request)
    {
        $site = $request->attributes->get('site');
        abort_unless($this->community->enabled($site, 'community'), 404);
        $rules = ['q' => 'nullable|string|max:100', 'category' => ['nullable', Rule::in(array_keys(Community::FORUMS))], 'sort' => 'nullable|in:recent,newest,replies'];
        if ($site->isNetwork()) {
            $rules['location'] = ['nullable', 'string', 'max:100', Rule::exists('locations', 'slug')->where('province', 'MB')->where('selectable', true)];
        }
        $filters = $request->validate($rules);
        $matching = $this->community->threads($site->location_id)
            ->when($filters['q'] ?? null, fn ($query, $term) => KeywordSearch::apply($query, $term, ['title', 'body']))
            ->when($filters['location'] ?? null, fn ($query, $slug) => $query->whereHas('location', fn ($place) => $place->where('slug', $slug)));
        $categoryCounts = (clone $matching)->toBase()->select('category')->selectRaw('COUNT(*) as total')->groupBy('category')->pluck('total', 'category');
        $threads = $matching->when($filters['category'] ?? null, fn ($query, $category) => $query->where('category', $category))
            ->orderByDesc('pinned');
        match ($filters['sort'] ?? 'recent') {
            'newest' => $threads->latest('created_at'),
            'replies' => $threads->orderByDesc('replies_count')->orderByDesc('last_activity_at'),
            default => $threads->orderByDesc('last_activity_at'),
        };
        $threads = $threads->orderByDesc('id')->paginate(18)->withQueryString();

        // The Discussions hub includes an optional, day-grouped event timeline.
        // No hidden or out-of-scope events are exposed.
        $eventsEnabled = $this->community->enabled($site, 'events');
        $eventDays = collect();
        if ($eventsEnabled) {
            $start = now('UTC')->format('Y-m-d H:i:s');
            $until = now('America/Winnipeg')->addDays(60)->endOfDay()->utc()->format('Y-m-d H:i:s');
            $eventPlace = LocationScope::forBrowsing($site, $filters['location'] ?? null);
            $events = $this->community->events($eventPlace?->id)
                ->where('status', 'approved')->where('starts_at', '<=', $until)
                ->where(fn ($query) => $query->where('starts_at', '>=', $start)->orWhere('ends_at', '>=', $start))
                ->orderBy('starts_at')->orderBy('id')->limit(60)->get();
            $today = now('America/Winnipeg')->toDateString();
            $eventDays = $events->groupBy(fn (CommunityEvent $event) => max($today, $event->localStart()->toDateString()));
        }

        $places = $site->isNetwork() ? Location::selectable()->orderBy('name')->get() : collect();

        return view('community.forum', compact('threads', 'filters', 'eventDays', 'eventsEnabled', 'places', 'categoryCounts'));
    }

    public function thread(Request $request, int $thread)
    {
        $item = $this->visible($request)->findOrFail($thread);
        $site = $request->attributes->get('site');
        $canonical = NetworkUrl::to($this->community->canonicalSite($item, 'community'), '/community/'.$item->id);
        if (parse_url($canonical, PHP_URL_HOST) !== $site->domain->host) {
            return redirect()->away($canonical, 301, ['Cache-Control' => 'no-store']);
        }

        $replies = $item->replies()->where('status', 'approved')->with('author')->oldest('id')->paginate(25);
        $following = $request->user() && DB::table('community_thread_follows')
            ->where(['thread_id' => $thread, 'user_id' => $request->user()->id])->exists();

        $related = $this->community->threads($site->location_id)->whereKeyNot($item->id)
            ->where('category', $item->category)
            ->when($item->location_id, fn ($query, $id) => $query->where('location_id', $id))
            ->orderByDesc('last_activity_at')->latest('id')->limit(3)->get();

        return view('community.thread', compact('item', 'canonical', 'replies', 'following', 'related'));
    }

    public function owned(Request $request)
    {
        $site = $request->attributes->get('site');
        abort_unless($this->community->enabled($site, 'community'), 404);
        $statuses = ['pending', 'approved', 'rejected'];
        $filters = $request->validate(['status' => ['nullable', Rule::in($statuses)], 'q' => 'nullable|string|max:100']);
        $owned = CommunityThread::where('author_id', $request->user()->id)
            ->when($site->location_id, fn ($query, $id) => $query->where('location_id', $id));
        $counts = (clone $owned)->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
        $items = $owned->with('location')->withCount(['replies' => fn ($query) => $query->where('status', 'approved')])
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['q'] ?? null, fn ($query, $term) => KeywordSearch::apply($query, $term, ['title', 'body']))
            ->latest('id')->paginate(18)->withQueryString();

        return view('community.owned', compact('items', 'counts', 'statuses', 'filters'));
    }

    public function followed(Request $request)
    {
        $site = $request->attributes->get('site');
        abort_unless($this->community->enabled($site, 'community'), 404);

        $threads = $this->community->threads($site->location_id)
            ->whereIn('community_threads.id', DB::table('community_thread_follows')
                ->select('thread_id')->where('user_id', $request->user()->id))
            ->orderByDesc('last_activity_at')->orderByDesc('id')
            ->paginate(18);

        return view('community.followed', compact('threads'));
    }

    public function replyPermalink(Request $request, int $thread, int $reply)
    {
        $item = $this->visible($request)->findOrFail($thread);
        $approved = $item->replies()->where('status', 'approved')->findOrFail($reply);
        $position = $item->replies()->where('status', 'approved')->where('id', '<=', $approved->id)->count();
        $page = (int) ceil($position / 25);
        $canonical = NetworkUrl::to($this->community->canonicalSite($item, 'community'), '/community/'.$item->id);

        return redirect()->away($canonical.($page > 1 ? '?page='.$page : '').'#reply-'.$approved->id,
            302, ['Cache-Control' => 'no-store']);
    }

    public function create(Request $request)
    {
        $site = $request->attributes->get('site');
        abort_unless($this->community->enabled($site, 'community'), 404);

        $eventsEnabled = $this->community->enabled($site, 'events');
        $postType = $request->query('type') === 'event' ? 'event' : 'discussion';
        abort_if($postType === 'event' && ! $eventsEnabled, 404);

        $category = $request->query('category');

        return view('community.new-thread', [
            'places' => $site->isNetwork() ? Location::selectable()->orderBy('name')->get() : collect(),
            'eventsEnabled' => $eventsEnabled,
            'postType' => $postType,
            'suggestedLocation' => LocationScope::forBrowsing($site, $request->query('location', $request->cookie(LocationScope::PREFERENCE_COOKIE))),
            'suggestedCategory' => is_string($category) && array_key_exists($category, Community::FORUMS) ? $category : null,
        ]);
    }

    public function store(Request $request)
    {
        $site = $request->attributes->get('site');
        abort_unless($this->community->enabled($site, 'community'), 404);
        $data = $request->validate([
            'title' => 'required|string|max:140', 'body' => 'required|string|min:10|max:5000',
            'category' => ['required', Rule::in(array_keys(Community::FORUMS))], 'location_id' => 'nullable|integer',
        ]);
        $requested = isset($data['location_id']) ? (int) $data['location_id'] : null;
        $data['location_id'] = $site->isNetwork() && $requested === null ? null : LocationScope::forPublication($site, $requested);
        CommunityThread::create($data + ['author_id' => $request->user()->id]);

        return redirect('/community')->with('status', 'Discussion submitted for review.');
    }

    public function reply(Request $request, int $thread)
    {
        $data = $request->validate(['body' => 'required|string|min:5|max:3000']);
        DB::transaction(function () use ($request, $thread, $data) {
            $item = $this->visible($request)->lockForUpdate()->findOrFail($thread);
            abort_if($item->locked, 409, 'This discussion is locked.');
            $item->replies()->create($data + ['author_id' => $request->user()->id]);
        });

        return redirect('/community/'.$thread)->with('status', 'Reply submitted for review.');
    }

    public function follow(Request $request, int $thread)
    {
        $item = $this->visible($request)->findOrFail($thread);
        DB::table('community_thread_follows')->insertOrIgnore(['thread_id' => $item->id, 'user_id' => $request->user()->id]);

        return redirect('/community/'.$thread)->with('status', 'You are following this discussion.');
    }

    public function unfollow(Request $request, int $thread)
    {
        DB::table('community_thread_follows')->where(['thread_id' => $thread, 'user_id' => $request->user()->id])->delete();

        return redirect('/community/'.$thread)->with('status', 'You stopped following this discussion.');
    }

    public function notifications(Request $request)
    {
        $view = $request->validate(['view' => 'sometimes|in:all,unread'])['view'] ?? 'all';
        $unreadCount = $this->visibleNotifications($request)->whereNull('notifications.read_at')->count();
        $notifications = $this->visibleNotifications($request)
            ->when($view === 'unread', fn (Builder $query) => $query->whereNull('notifications.read_at'))
            ->orderByRaw('CASE WHEN notifications.read_at IS NULL THEN 0 ELSE 1 END')
            ->orderByDesc('notifications.id')
            ->paginate(20, ['notifications.id', 'notifications.reply_id', 'notifications.read_at', 'notifications.created_at', 'threads.id as thread_id', 'threads.title'])
            ->withQueryString();

        return view('community.notifications', compact('notifications', 'view', 'unreadCount'));
    }

    public function readNotification(Request $request, int $notification)
    {
        abort_unless($this->visibleNotifications($request)->where('notifications.id', $notification)->exists(), 404);
        DB::table('community_notifications')->where(['id' => $notification, 'user_id' => $request->user()->id])
            ->whereNull('read_at')->update(['read_at' => now()]);

        $returnTo = $request->input('return_to');

        return in_array($returnTo, ['all', 'unread'], true)
            ? redirect('/account/community-notifications'.($returnTo === 'unread' ? '?view=unread' : ''))
                ->with('status', 'Notification marked as read.')
            : redirect('/account/notifications');
    }

    public function readAllNotifications(Request $request)
    {
        // Only mark the current user's approved, visible replies on this portal.
        // Use bounded updates to avoid large SQL parameter lists.
        $ids = $this->visibleNotifications($request)->whereNull('notifications.read_at')
            ->pluck('notifications.id');
        foreach ($ids->chunk(250) as $chunk) {
            DB::table('community_notifications')->where('user_id', $request->user()->id)
                ->whereIn('id', $chunk->all())->whereNull('read_at')
                ->update(['read_at' => now()]);
        }

        return redirect('/account/community-notifications')
            ->with('status', 'All visible discussion notifications marked as read.');
    }

    private function visibleNotifications(Request $request): Builder
    {
        $site = $request->attributes->get('site');
        abort_unless($this->community->enabled($site, 'community'), 404);

        return DB::table('community_notifications as notifications')
            ->join('community_replies as replies', 'replies.id', '=', 'notifications.reply_id')
            ->join('community_threads as threads', 'threads.id', '=', 'replies.thread_id')
            ->where('threads.status', 'approved')->where('replies.status', 'approved')
            ->where('notifications.user_id', $request->user()->id)
            ->when($site->location_id, fn ($query, $id) => $query->where('threads.location_id', $id));
    }

    private function visible(Request $request)
    {
        $site = $request->attributes->get('site');
        abort_unless($this->community->enabled($site, 'community'), 404);

        return $this->community->threads($site->location_id);
    }
}
