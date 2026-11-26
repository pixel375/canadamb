<?php

namespace App\Http\Controllers;

use App\Models\CommunityEvent;
use App\Models\CommunityReply;
use App\Models\CommunityThread;
use App\Models\Location;
use App\Support\Audit;
use App\Support\Community;
use App\Support\EventDiscussions;
use App\Support\KeywordSearch;
use App\Support\LocationScope;
use App\Support\NetworkUrl;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
            ->when(filled($filters['q'] ?? null), fn ($query) => KeywordSearch::apply($query, $filters['q'], ['title', 'body']))
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

        $event = EventDiscussions::publicEvent($site, $item);

        return view('community.thread', compact('item', 'canonical', 'replies', 'following', 'related', 'event'));
    }

    public function owned(Request $request)
    {
        $site = $request->attributes->get('site');
        abort_unless($this->community->enabled($site, 'community'), 404);
        $statuses = ['pending', 'approved', 'rejected', 'withdrawn'];
        $filters = $request->validate(['status' => ['nullable', Rule::in($statuses)], 'q' => 'nullable|string|max:100',
            'category' => ['nullable', Rule::in(array_keys(Community::FORUMS))], 'sort' => 'nullable|in:newest,oldest,replies']);
        $owned = CommunityThread::where('author_id', $request->user()->id)
            ->when($site->location_id, fn ($query, $id) => $query->where('location_id', $id));
        $counts = (clone $owned)->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
        $items = $owned->with('location')->withCount(['replies' => fn ($query) => $query->where('status', 'approved')])
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when(filled($filters['q'] ?? null), fn ($query) => KeywordSearch::apply($query, $filters['q'], ['title', 'body']))
            ->when($filters['category'] ?? null, fn ($query, $category) => $query->where('category', $category));
        match ($filters['sort'] ?? 'newest') {
            'oldest' => $items->oldest('id'),
            'replies' => $items->orderByDesc('replies_count')->latest('id'),
            default => $items->latest('id'),
        };
        $items = $items->paginate(18)->withQueryString();

        $linkedEvents = EventDiscussions::eventIdsForThreads($items->pluck('id')->all());

        return view('community.owned', compact('items', 'counts', 'statuses', 'filters', 'linkedEvents'));
    }

    public function edit(Request $request, int $thread)
    {
        $item = $this->ownedThread($request)->findOrFail($thread);
        abort_if($item->locked || ! in_array($item->status, ['pending', 'approved', 'rejected'], true), 409);

        abort_if(EventDiscussions::eventIdsForThreads([$item->id]) !== [], 409, 'Edit official event details through My events.');

        return view('community.edit-thread', compact('item'));
    }

    public function update(Request $request, int $thread)
    {
        $data = $request->validate([
            'title' => 'required|string|max:140', 'body' => 'required|string|min:10|max:5000',
            'category' => ['required', Rule::in(array_keys(Community::FORUMS))],
            'version' => 'required|string|size:64',
        ]);
        DB::transaction(function () use ($request, $thread, $data) {
            $item = $this->ownedThread($request)->lockForUpdate()->findOrFail($thread);
            abort_if($item->locked || ! in_array($item->status, ['pending', 'approved', 'rejected'], true), 409);
            abort_if(EventDiscussions::eventIdsForThreads([$item->id]) !== [], 409, 'Edit official event details through My events.');
            if (! hash_equals($item->contentVersion(), $data['version'])) {
                throw ValidationException::withMessages(['version' => 'This discussion changed. Reload the editor before saving.']);
            }
            $item->fill(collect($data)->except('version')->all());
            if (! $item->isDirty()) {
                return;
            }
            $item->forceFill(['status' => 'pending', 'pinned' => false])->save();
            Audit::record('community.thread.edited', $request->user()->id, context: ['thread' => $thread]);
        }, attempts: 3);

        return redirect('/account/discussions')->with('status', 'Discussion changes saved. Changed content requires moderation before publication.');
    }

    public function withdraw(Request $request, int $thread)
    {
        $data = $request->validate(['version' => 'nullable|string|size:64']);
        DB::transaction(function () use ($request, $thread, $data) {
            $item = $this->ownedThread($request)->lockForUpdate()->findOrFail($thread);
            abort_if($item->locked || ! in_array($item->status, ['pending', 'approved', 'rejected'], true), 409);
            if (isset($data['version']) && ! hash_equals($item->contentVersion(), $data['version'])) {
                throw ValidationException::withMessages(['version' => 'This discussion changed. Reload before withdrawing.']);
            }
            $item->forceFill(['status' => 'withdrawn', 'pinned' => false])->save();
            Audit::record('community.thread.withdrawn', $request->user()->id, context: ['thread' => $thread]);
        }, attempts: 3);

        return redirect('/account/discussions')->with('status', 'Discussion withdrawn from public view.');
    }

    private function ownedThread(Request $request)
    {
        $site = $request->attributes->get('site');
        abort_unless($this->community->enabled($site, 'community'), 404);

        return CommunityThread::where('author_id', $request->user()->id)
            ->when($site->location_id, fn ($query, $id) => $query->where('location_id', $id));
    }

    public function ownedReplies(Request $request)
    {
        $statuses = ['pending', 'approved', 'rejected', 'withdrawn'];
        $filters = $request->validate(['status' => ['nullable', Rule::in($statuses)], 'q' => 'nullable|string|max:100',
            'sort' => 'nullable|in:newest,oldest', 'thread' => 'nullable|integer|min:1']);
        $owned = $this->ownedReplyQuery($request);
        $counts = (clone $owned)->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
        $items = $owned->with('thread:id,title,status,locked')
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when(filled($filters['q'] ?? null), fn ($query) => KeywordSearch::apply($query, $filters['q'], ['body']))
            ->when($filters['thread'] ?? null, fn ($query, $thread) => $query->where('thread_id', $thread)->whereHas('thread', fn ($parent) => $parent->where('status', 'approved')))
            ->orderBy('id', ($filters['sort'] ?? 'newest') === 'oldest' ? 'asc' : 'desc')->paginate(20)->withQueryString();

        return view('community.owned-replies', compact('items', 'counts', 'statuses', 'filters'));
    }

    public function editReply(Request $request, int $reply)
    {
        $item = $this->ownedReplyQuery($request)->with('thread')->findOrFail($reply);
        $this->ensureReplyEditable($item->thread, $item);

        return view('community.edit-reply', compact('item'));
    }

    public function updateReply(Request $request, int $reply)
    {
        $data = $request->validate(['body' => 'required|string|min:5|max:3000', 'version' => 'required|string|size:64']);
        DB::transaction(function () use ($request, $reply, $data) {
            $initial = $this->ownedReplyQuery($request)->findOrFail($reply);
            $thread = CommunityThread::lockForUpdate()->findOrFail($initial->thread_id);
            $item = $this->ownedReplyQuery($request)->lockForUpdate()->findOrFail($reply);
            $this->ensureReplyEditable($thread, $item);
            $this->checkReplyVersion($item, $data['version']);
            if ($item->body === $data['body']) {
                return;
            }
            $item->forceFill(['body' => $data['body'], 'status' => 'pending'])->save();
            Audit::record('community.reply.edited', $request->user()->id, context: ['reply' => $reply]);
        }, attempts: 3);

        return redirect('/account/replies')->with('status', 'Reply saved. Changed content requires moderation before publication.');
    }

    public function withdrawReply(Request $request, int $reply)
    {
        $data = $request->validate(['version' => 'required|string|size:64']);
        DB::transaction(function () use ($request, $reply, $data) {
            $initial = $this->ownedReplyQuery($request)->findOrFail($reply);
            $thread = CommunityThread::lockForUpdate()->findOrFail($initial->thread_id);
            $item = $this->ownedReplyQuery($request)->lockForUpdate()->findOrFail($reply);
            $this->ensureReplyEditable($thread, $item);
            $this->checkReplyVersion($item, $data['version']);
            $item->forceFill(['status' => 'withdrawn'])->save();
            Audit::record('community.reply.withdrawn', $request->user()->id, context: ['reply' => $reply]);
        }, attempts: 3);

        return redirect('/account/replies')->with('status', 'Reply withdrawn from public view.');
    }

    private function ownedReplyQuery(Request $request)
    {
        $site = $request->attributes->get('site');
        abort_unless($this->community->enabled($site, 'community'), 404);

        return CommunityReply::where('author_id', $request->user()->id)
            ->whereHas('thread', fn ($query) => $query->when($site->location_id, fn ($thread, $id) => $thread->where('location_id', $id)));
    }

    private function ensureReplyEditable(CommunityThread $thread, CommunityReply $item): void
    {
        abort_if($thread->status !== 'approved' || $thread->locked || ! in_array($item->status, ['pending', 'approved', 'rejected'], true), 409);
    }

    private function checkReplyVersion(CommunityReply $item, string $version): void
    {
        if (! hash_equals($item->contentVersion(), $version)) {
            throw ValidationException::withMessages(['version' => 'This reply changed. Reload before saving.']);
        }
    }

    public function followed(Request $request)
    {
        $site = $request->attributes->get('site');
        abort_unless($this->community->enabled($site, 'community'), 404);

        $filters = $request->validate(['q' => 'nullable|string|max:100', 'category' => ['nullable', Rule::in(array_keys(Community::FORUMS))]]);
        $follows = DB::table('community_thread_follows')->where('user_id', $request->user()->id);
        $threads = $this->community->threads($site->location_id)
            ->whereIn('community_threads.id', (clone $follows)->select('thread_id'))
            ->when(filled($filters['q'] ?? null), fn ($query) => KeywordSearch::apply($query, $filters['q'], ['title', 'body']))
            ->when($filters['category'] ?? null, fn ($query, $category) => $query->where('category', $category))
            ->orderByDesc('last_activity_at')->orderByDesc('id')
            ->paginate(18)->withQueryString();
        $hidden = $follows->whereIn('thread_id', $this->unavailableFollowIds($request))->count();

        return view('community.followed', compact('threads', 'filters', 'hidden'));
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
        $site = $request->attributes->get('site');
        abort_unless($this->community->enabled($site, 'community'), 404);
        CommunityThread::when($site->location_id, fn ($query, $id) => $query->where('location_id', $id))
            ->whereIn('id', DB::table('community_thread_follows')->where('user_id', $request->user()->id)->select('thread_id'))
            ->findOrFail($thread);
        DB::table('community_thread_follows')->where(['thread_id' => $thread, 'user_id' => $request->user()->id])->delete();

        return redirect($request->input('return_to') === 'following' ? '/account/followed-discussions' : '/community/'.$thread)
            ->with('status', 'You stopped following this discussion.');
    }

    public function clearUnavailableFollows(Request $request)
    {
        $request->validate(['confirm_remove' => 'accepted']);
        $site = $request->attributes->get('site');
        abort_unless($this->community->enabled($site, 'community'), 404);
        $removed = DB::table('community_thread_follows')->where('user_id', $request->user()->id)
            ->whereIn('thread_id', $this->unavailableFollowIds($request))->delete();

        return redirect('/account/followed-discussions')->with('status', $removed.' unavailable discussion follows removed.');
    }

    private function unavailableFollowIds(Request $request)
    {
        $site = $request->attributes->get('site');

        return CommunityThread::where('status', '!=', 'approved')
            ->when($site->location_id, fn ($query, $id) => $query->where('location_id', $id))->select('id');
    }

    public function notifications(Request $request)
    {
        $view = $request->validate(['view' => 'sometimes|in:all,unread'])['view'] ?? 'all';
        $unread = $this->visibleNotifications($request)->whereNull('notifications.read_at');
        $unreadCount = (clone $unread)->count();
        $readCutoff = $unread->max('notifications.id');
        $notifications = $this->visibleNotifications($request)
            ->when($view === 'unread', fn (Builder $query) => $query->whereNull('notifications.read_at'))
            ->orderByRaw('CASE WHEN notifications.read_at IS NULL THEN 0 ELSE 1 END')
            ->orderByDesc('notifications.id')
            ->paginate(20, ['notifications.id', 'notifications.reply_id', 'notifications.read_at', 'notifications.created_at', 'threads.id as thread_id', 'threads.title'])
            ->withQueryString();

        return view('community.notifications', compact('notifications', 'view', 'unreadCount', 'readCutoff'));
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
        $visible = $this->visibleNotifications($request)->whereNull('notifications.read_at');
        $data = $request->validate(['cutoff' => 'nullable|integer|min:1']);
        $latest = $data['cutoff'] ?? (clone $visible)->max('notifications.id');
        if ($latest !== null) {
            $visible->where('notifications.id', '<=', $latest)->select('notifications.id as id')
                ->chunkById(250, function ($rows) use ($request) {
                    DB::table('community_notifications')->where('user_id', $request->user()->id)
                        ->whereIn('id', $rows->pluck('id')->all())->whereNull('read_at')
                        ->update(['read_at' => now()]);
                }, 'notifications.id', 'id');
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
