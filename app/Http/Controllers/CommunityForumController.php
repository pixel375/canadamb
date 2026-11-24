<?php

namespace App\Http\Controllers;

use App\Models\CommunityEvent;
use App\Models\CommunityThread;
use App\Models\Location;
use App\Support\Community;
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
        $filters = $request->validate(['q' => 'nullable|string|max:100', 'category' => ['nullable', Rule::in(array_keys(Community::FORUMS))]]);
        $threads = $this->community->threads($site->location_id)
            ->when($filters['q'] ?? null, fn ($query, $term) => $query->whereLike('title', '%'.$term.'%'))
            ->when($filters['category'] ?? null, fn ($query, $category) => $query->where('category', $category))
            ->orderByDesc('pinned')->orderByDesc('last_activity_at')->orderByDesc('id')->paginate(18)->withQueryString();

        // The Discussions hub includes an optional, day-grouped event timeline.
        // No hidden or out-of-scope events are exposed.
        $eventsEnabled = $this->community->enabled($site, 'events');
        $eventDays = collect();
        if ($eventsEnabled) {
            $start = now('UTC')->format('Y-m-d H:i:s');
            $until = now('America/Winnipeg')->addDays(60)->endOfDay()->utc()->format('Y-m-d H:i:s');
            $events = $this->community->events($site->location_id)
                ->where('status', 'approved')
                ->whereBetween('starts_at', [$start, $until])
                ->orderBy('starts_at')->orderBy('id')->limit(60)->get();
            $eventDays = $events->groupBy(fn (CommunityEvent $event) => $event->localStart()->toDateString());
        }

        return view('community.forum', compact('threads', 'filters', 'eventDays', 'eventsEnabled'));
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

        return view('community.thread', compact('item', 'canonical', 'replies', 'following'));
    }

    public function create(Request $request)
    {
        $site = $request->attributes->get('site');
        abort_unless($this->community->enabled($site, 'community'), 404);

        $eventsEnabled = $this->community->enabled($site, 'events');
        $postType = $request->query('type') === 'event' ? 'event' : 'discussion';
        abort_if($postType === 'event' && ! $eventsEnabled, 404);

        return view('community.new-thread', [
            'places' => $site->isNetwork() ? Location::selectable()->orderBy('name')->get() : collect(),
            'eventsEnabled' => $eventsEnabled,
            'postType' => $postType,
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
        $notifications = $this->visibleNotifications($request)
            ->orderByDesc('notifications.id')->paginate(20, ['notifications.id', 'notifications.read_at', 'notifications.created_at', 'threads.id as thread_id', 'threads.title']);

        return view('community.notifications', compact('notifications'));
    }

    public function readNotification(Request $request, int $notification)
    {
        abort_unless($this->visibleNotifications($request)->where('notifications.id', $notification)->exists(), 404);
        DB::table('community_notifications')->where(['id' => $notification, 'user_id' => $request->user()->id])
            ->whereNull('read_at')->update(['read_at' => now()]);

        return redirect('/account/notifications');
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
