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
use App\Support\OwnerNotifications;
use App\Support\PublicationPolicy;
use App\Support\ReplyLinks;
use App\Support\ReplyPublication;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CommunityForumController extends Controller
{
    private const NOTIFICATION_PAGE_SIZE = 20;

    public function __construct(private readonly Community $community) {}

    public function index(Request $request)
    {
        $site = $request->attributes->get('site');
        abort_unless($this->community->enabled($site, 'community'), 404);
        $rules = ['q' => 'nullable|string|max:100', 'category' => ['nullable', Rule::in(array_keys(Community::FORUMS))], 'sort' => 'nullable|in:recent,newest,replies',
            'topics' => 'nullable|in:open,unanswered,following', 'activity' => 'nullable|in:7,30'];
        if ($site->isNetwork()) {
            $rules['location'] = ['nullable', 'string', 'max:100', Rule::exists('locations', 'slug')->where('province', 'MB')->where('selectable', true)];
        }
        $filters = $request->validate($rules);
        $matching = $this->community->threads($site->location_id)
            ->when(filled($filters['q'] ?? null), fn ($query) => KeywordSearch::apply($query, $filters['q'], ['title', 'body']))
            ->when($filters['location'] ?? null, fn ($query, $slug) => $query->whereHas('location', fn ($place) => $place->where('slug', $slug)));
        if (($filters['topics'] ?? null) === 'open') {
            $matching->where('locked', false);
        } elseif (($filters['topics'] ?? null) === 'unanswered') {
            $matching->where('locked', false)->whereDoesntHave('replies', fn ($query) => $query->where('status', 'approved'));
        } elseif (($filters['topics'] ?? null) === 'following') {
            abort_unless($request->user()?->hasVerifiedEmail(), 403);
            $matching->whereExists(fn (Builder $query) => $query->selectRaw('1')->from('community_thread_follows')
                ->whereColumn('thread_id', 'community_threads.id')->where('user_id', $request->user()->id));
        }
        if (isset($filters['activity'])) {
            $since = now()->subDays((int) $filters['activity']);
            $matching->where(fn ($query) => $query->where('last_activity_at', '>=', $since)
                ->orWhere(fn ($query) => $query->whereNull('last_activity_at')->where('created_at', '>=', $since)));
        }
        $categoryCounts = (clone $matching)->toBase()->select('category')->selectRaw('COUNT(*) as total')->groupBy('category')->pluck('total', 'category');
        $threads = $matching->when($filters['category'] ?? null, fn ($query, $category) => $query->where('category', $category))
            ->orderByDesc('pinned');
        match ($filters['sort'] ?? 'recent') {
            'newest' => $threads->latest('created_at'),
            'replies' => $threads->orderByDesc('replies_count')->orderByDesc('last_activity_at'),
            default => $threads->orderByDesc('last_activity_at'),
        };
        $threads = $threads->orderByDesc('id')->paginate(18)->appends(LocationScope::preserveAll($filters));

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
                ->where(fn ($query) => $query->where('starts_at', '>=', $start)->orWhere('ends_at', '>', $start))
                ->orderBy('starts_at')->orderBy('id')->limit(60)->get();
            $today = now('America/Winnipeg')->toDateString();
            $eventDays = $events->groupBy(fn (CommunityEvent $event) => max($today, $event->localStart()->toDateString()));
        }

        $places = $site->isNetwork() ? Location::selectable()->orderBy('name')->get() : collect();

        return response()->view('community.forum', compact('threads', 'filters', 'eventDays', 'eventsEnabled', 'places', 'categoryCounts'))
            ->header('Cache-Control', 'private, no-store');
    }

    public function thread(Request $request, int $thread)
    {
        $item = $this->visible($request)->findOrFail($thread);
        $site = $request->attributes->get('site');
        $canonical = NetworkUrl::to($this->community->canonicalSite($item, 'community'), '/community/'.$item->id);

        $replies = $item->replies()->where('status', 'approved')->with('author')->oldest('id')->paginate(25)->appends(LocationScope::detailParams());
        $replyLinksEnabled = ReplyLinks::enabled();
        $parents = ReplyLinks::publicParents($item, $replies->pluck('id')->all());
        $replyTo = null;
        if ($replyLinksEnabled && $request->filled('reply_to')) {
            $selection = $request->validate(['reply_to' => 'required|integer|min:1']);
            $replyTo = $item->replies()->where('status', 'approved')->with('author')->findOrFail($selection['reply_to']);
        }
        $following = $request->user() && DB::table('community_thread_follows')
            ->where(['thread_id' => $thread, 'user_id' => $request->user()->id])->exists();

        $related = $this->community->threads($site->location_id)->whereKeyNot($item->id)
            ->where('category', $item->category)
            ->when($item->location_id, fn ($query, $id) => $query->where('location_id', $id))
            ->orderByDesc('last_activity_at')->latest('id')->limit(3)->get();

        $event = EventDiscussions::publicEvent($site, $item);
        $eventReviewPending = ! $event && EventDiscussions::pendingReview($site, $item);

        return view('community.thread', compact('item', 'canonical', 'replies', 'following', 'related', 'event', 'eventReviewPending', 'replyLinksEnabled', 'parents', 'replyTo'));
    }

    public function owned(Request $request)
    {
        $site = $request->attributes->get('site');
        abort_unless($this->community->enabled($site, 'community'), 404);
        $statuses = ['pending', 'approved', 'rejected', 'withdrawn'];
        $filters = $request->validate(['status' => ['nullable', Rule::in($statuses)], 'q' => 'nullable|string|max:100',
            'category' => ['nullable', Rule::in(array_keys(Community::FORUMS))], 'sort' => 'nullable|in:newest,oldest,replies',
            'reference' => 'nullable|integer|min:1']);
        $owned = CommunityThread::where('author_id', $request->user()->id)
            ->when($site->location_id, fn ($query, $id) => $query->where('location_id', $id));
        $owned->when($filters['reference'] ?? null, fn ($query, $id) => $query->whereKey($id))
            ->when(filled($filters['q'] ?? null), fn ($query) => KeywordSearch::apply($query, $filters['q'], ['title', 'body']))
            ->when($filters['category'] ?? null, fn ($query, $category) => $query->where('category', $category));
        $counts = (clone $owned)->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
        $items = $owned->with('location')->withCount(['replies' => fn ($query) => $query->where('status', 'approved')])
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status));
        match ($filters['sort'] ?? 'newest') {
            'oldest' => $items->oldest('id'),
            'replies' => $items->orderByDesc('replies_count')->latest('id'),
            default => $items->latest('id'),
        };
        $items = $items->paginate(18)->appends($filters);

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
        DB::transaction(function () use ($request, $thread) {
            $item = $this->ownedThread($request)->lockForUpdate()->findOrFail($thread);
            abort_if($item->locked || ! in_array($item->status, ['pending', 'approved', 'rejected'], true), 409);
            abort_if(EventDiscussions::eventIdsForThreads([$item->id]) !== [], 409, 'Edit official event details through My events.');
            $data = $request->validate([
                'title' => 'required|string|max:140', 'body' => 'required|string|min:10|max:5000',
                'category' => ['required', Rule::in(array_keys(Community::FORUMS))],
                'version' => 'required|string|size:64',
            ]);
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
        DB::transaction(function () use ($request, $thread) {
            $item = $this->ownedThread($request)->lockForUpdate()->findOrFail($thread);
            abort_if($item->locked || ! in_array($item->status, ['pending', 'approved', 'rejected'], true), 409);
            $data = $request->validate(['version' => 'required|string|size:64']);
            if (! hash_equals($item->contentVersion(), $data['version'])) {
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
            'sort' => 'nullable|in:newest,oldest', 'thread' => 'nullable|integer|min:1', 'reference' => 'nullable|integer|min:1']);
        $owned = $this->ownedReplyQuery($request);
        $owned->when($filters['reference'] ?? null, fn ($query, $id) => $query->whereKey($id))
            ->when(filled($filters['q'] ?? null), fn ($query) => KeywordSearch::apply($query, $filters['q'], ['body']))
            ->when($filters['thread'] ?? null, fn ($query, $thread) => $query->where('thread_id', $thread)->whereHas('thread', fn ($parent) => $parent->where('status', 'approved')));
        $counts = (clone $owned)->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
        $items = $owned->with('thread:id,title,status,locked')
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->orderBy('id', ($filters['sort'] ?? 'newest') === 'oldest' ? 'asc' : 'desc')->paginate(20)->appends($filters);

        return view('community.owned-replies', compact('items', 'counts', 'statuses', 'filters'));
    }

    public function editReply(Request $request, int $reply)
    {
        $item = $this->ownedReplyQuery($request)->with('thread')->findOrFail($reply);
        $this->ensureReplyEditable($item->thread, $item);

        $origin = $request->validate(['from' => 'nullable|in:discussion', 'page' => 'nullable|integer|min:1|max:100000']);
        $returnPath = $this->replyReturnPath($item, $origin);

        return view('community.edit-reply', compact('item', 'origin', 'returnPath'));
    }

    public function updateReply(Request $request, int $reply)
    {
        $returnPath = DB::transaction(function () use ($request, $reply) {
            $initial = $this->ownedReplyQuery($request)->findOrFail($reply);
            $thread = CommunityThread::lockForUpdate()->findOrFail($initial->thread_id);
            $item = $this->ownedReplyQuery($request)->lockForUpdate()->findOrFail($reply);
            $this->ensureReplyEditable($thread, $item);
            $data = $request->validate(['body' => 'required|string|min:5|max:3000', 'version' => 'required|string|size:64', 'from' => 'nullable|in:discussion', 'page' => 'nullable|integer|min:1|max:100000']);
            $this->checkReplyVersion($item, $data['version']);
            if ($item->body !== $data['body']) {
                $item->forceFill(['body' => $data['body'], 'status' => 'pending'])->save();
                Audit::record('community.reply.edited', $request->user()->id, context: ['reply' => $reply]);
            }

            return $this->replyReturnPath($item, $data);
        }, attempts: 3);

        return redirect($returnPath)->with('status', 'Reply saved. Changed content requires moderation before publication.');
    }

    private function replyReturnPath(CommunityReply $item, array $origin): string
    {
        if (($origin['from'] ?? null) !== 'discussion') {
            return '/account/replies';
        }
        $page = (int) ($origin['page'] ?? 1);

        return LocationScope::detailUrl('/community/'.$item->thread_id, $page > 1 ? ['page' => $page] : [], '#discussion-replies');
    }

    public function withdrawReply(Request $request, int $reply)
    {
        DB::transaction(function () use ($request, $reply) {
            $initial = $this->ownedReplyQuery($request)->findOrFail($reply);
            $thread = CommunityThread::lockForUpdate()->findOrFail($initial->thread_id);
            $item = $this->ownedReplyQuery($request)->lockForUpdate()->findOrFail($reply);
            $this->ensureReplyEditable($thread, $item);
            $data = $request->validate(['version' => 'required|string|size:64']);
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

        $filters = $this->followFilters($request);
        $follows = DB::table('community_thread_follows')->where('user_id', $request->user()->id);
        $threads = $this->community->threads($site->location_id)
            ->whereIn('community_threads.id', (clone $follows)->select('thread_id'))
            ->when(filled($filters['q'] ?? null), fn ($query) => KeywordSearch::apply($query, $filters['q'], ['title', 'body']))
            ->when($filters['category'] ?? null, fn ($query, $category) => $query->where('category', $category))
            ->orderByDesc('last_activity_at')->orderByDesc('id')
            ->paginate(18)->appends($filters);
        $hidden = $follows->whereIn('thread_id', $this->unavailableFollowIds($request))->count();

        return view('community.followed', compact('threads', 'filters', 'hidden'));
    }

    public function replyPermalink(Request $request, int $thread, int $reply)
    {
        $item = $this->visible($request)->findOrFail($thread);
        $approved = $item->replies()->where('status', 'approved')->findOrFail($reply);
        $position = $item->replies()->where('status', 'approved')->where('id', '<=', $approved->id)->count();
        $page = (int) ceil($position / 25);
        $destination = NetworkUrl::to($request->attributes->get('site'), LocationScope::detailUrl('/community/'.$item->id, $page > 1 ? ['page' => $page] : [], '#reply-'.$approved->id));

        return redirect()->away($destination,
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
        $reuse = $request->validate(['reuse' => 'nullable|integer|min:1'])['reuse'] ?? null;
        abort_if($reuse && $postType !== 'event', 404);
        $source = $reuse ? $this->community->events($site->location_id)
            ->where('author_id', $request->user()->id)->findOrFail($reuse) : null;

        return view('community.new-thread', [
            'source' => $source,
            'places' => $site->isNetwork() ? Location::selectable()->orderBy('name')->get() : collect(),
            'eventsEnabled' => $eventsEnabled,
            'postType' => $postType,
            'suggestedLocation' => $source?->location ?? LocationScope::forBrowsing($site, $request->query('location', $request->cookie(LocationScope::PREFERENCE_COOKIE))),
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
        $item = DB::transaction(function () use ($request, $data) {
            $item = CommunityThread::create($data + ['author_id' => $request->user()->id]);
            if (PublicationPolicy::allows('discussions', $request->user(), $item->location_id)) {
                $item->forceFill(['status' => 'approved', 'last_activity_at' => now()])->save();
                OwnerNotifications::record($item->author_id, 'discussion', $item->id, 'approved');
                Audit::record('community.thread.auto-approved', null, context: ['thread' => $item->id]);
            }

            return $item;
        }, attempts: 3);

        return redirect('/community')->with('status', $item->status === 'approved' ? 'Discussion published.' : 'Discussion submitted for review.');
    }

    public function reply(Request $request, int $thread)
    {
        [$reply, $hasParent] = DB::transaction(function () use ($request, $thread) {
            $item = $this->visible($request)->lockForUpdate()->findOrFail($thread);
            abort_if($item->locked, 409, 'This discussion is locked.');
            $selection = $request->validate(['parent_reply_id' => 'nullable|integer|min:1']);
            $parent = null;
            if (isset($selection['parent_reply_id'])) {
                abort_unless(ReplyLinks::enabled(), 404);
                $parent = $item->replies()->where('status', 'approved')->lockForUpdate()->findOrFail($selection['parent_reply_id']);
            }
            $data = $request->validate(['body' => 'required|string|min:5|max:3000']);
            $reply = $item->replies()->create(['body' => $data['body'], 'author_id' => $request->user()->id]);
            if ($parent) {
                ReplyLinks::attach($reply, $parent);
            }
            if (PublicationPolicy::allows('replies', $request->user(), $item->location_id)) {
                ReplyPublication::approve($item, $reply);
                OwnerNotifications::record($reply->author_id, 'reply', $reply->id, 'approved');
                Audit::record('community.reply.auto-approved', null, context: ['reply' => $reply->id]);
            }

            return [$reply, $parent !== null];
        }, attempts: 3);

        $destination = $hasParent && $reply->status === 'approved'
            ? '/community/'.$thread.'/replies/'.$reply->id : '/community/'.$thread;

        return redirect(LocationScope::detailUrl($destination))->with('status', $reply->status === 'approved' ? 'Reply published.' : 'Reply submitted for review.');
    }

    public function follow(Request $request, int $thread)
    {
        DB::transaction(function () use ($request, $thread) {
            $item = $this->visible($request)->lockForUpdate()->findOrFail($thread);
            DB::table('community_thread_follows')->insertOrIgnore(['thread_id' => $item->id, 'user_id' => $request->user()->id]);
        }, attempts: 3);

        return redirect(LocationScope::detailUrl('/community/'.$thread))->with('status', 'You are following this discussion.');
    }

    public function unfollow(Request $request, int $thread)
    {
        $site = $request->attributes->get('site');
        abort_unless($this->community->enabled($site, 'community'), 404);
        CommunityThread::when($site->location_id, fn ($query, $id) => $query->where('location_id', $id))
            ->whereIn('id', DB::table('community_thread_follows')->where('user_id', $request->user()->id)->select('thread_id'))
            ->findOrFail($thread);
        $returnToFollowing = $request->input('return_to') === 'following';
        $filters = $returnToFollowing ? $this->followFilters($request) : [];
        DB::table('community_thread_follows')->where(['thread_id' => $thread, 'user_id' => $request->user()->id])->delete();

        return redirect($returnToFollowing ? $this->followReturnPath($filters) : LocationScope::detailUrl('/community/'.$thread))
            ->with('status', 'You stopped following this discussion.');
    }

    public function clearUnavailableFollows(Request $request)
    {
        $site = $request->attributes->get('site');
        abort_unless($this->community->enabled($site, 'community'), 404);
        $request->validate(['confirm_remove' => 'accepted']);
        $filters = $this->followFilters($request);
        $removed = DB::table('community_thread_follows')->where('user_id', $request->user()->id)
            ->whereIn('thread_id', $this->unavailableFollowIds($request))->delete();

        return redirect($this->followReturnPath($filters))->with('status', $removed.' unavailable discussion follows removed.');
    }

    private function followFilters(Request $request): array
    {
        return $request->validate(['q' => 'nullable|string|max:100', 'category' => ['nullable', Rule::in(array_keys(Community::FORUMS))]]);
    }

    private function followReturnPath(array $filters): string
    {
        $query = http_build_query(array_filter($filters, fn ($value) => $value !== null && $value !== ''), '', '&', PHP_QUERY_RFC3986);

        return '/account/followed-discussions'.($query === '' ? '' : '?'.$query);
    }

    private function unavailableFollowIds(Request $request)
    {
        $site = $request->attributes->get('site');

        return CommunityThread::where('status', '!=', 'approved')
            ->when($site->location_id, fn ($query, $id) => $query->where('location_id', $id))->select('id');
    }

    public function notifications(Request $request)
    {
        $filters = $request->validate(['view' => 'sometimes|in:all,unread,read', 'q' => 'nullable|string|max:100', 'period' => 'nullable|in:7,30,90']);
        $view = $filters['view'] ?? 'all';
        $unread = $this->visibleNotifications($request)->whereNull('notifications.read_at');
        $unreadCount = (clone $unread)->count();
        $readCutoff = $unread->max('notifications.id');
        $notifications = $this->matchingNotifications($request, $filters)->orderByRaw('CASE WHEN notifications.read_at IS NULL THEN 0 ELSE 1 END')
            ->orderByDesc('notifications.id')
            ->paginate(self::NOTIFICATION_PAGE_SIZE, ['notifications.id', 'notifications.reply_id', 'notifications.read_at', 'notifications.created_at', 'threads.id as thread_id', 'threads.title', 'actors.name as actor_name'])
            ->appends($filters);
        $directReplies = ReplyLinks::directNotificationIds($request->user()->id, $notifications->pluck('reply_id')->all());

        $eventThreads = EventDiscussions::publicThreadIds($request->attributes->get('site'), $notifications->pluck('thread_id')->all());

        return response()->view('community.notifications', compact('notifications', 'view', 'unreadCount', 'readCutoff', 'filters', 'directReplies', 'eventThreads'))
            ->header('Cache-Control', 'private, no-store');
    }

    public function readNotification(Request $request, int $notification)
    {
        abort_unless($this->visibleNotifications($request)->where('notifications.id', $notification)->exists(), 404);
        $returnTo = $request->input('return_to');
        $filters = $request->validate(['q' => 'nullable|string|max:100', 'period' => 'nullable|in:7,30,90', 'page' => 'nullable|integer|min:1']);
        DB::table('community_notifications')->where(['id' => $notification, 'user_id' => $request->user()->id])
            ->whereNull('read_at')->update(['read_at' => now()]);

        return in_array($returnTo, ['all', 'unread', 'read'], true)
            ? redirect($this->notificationReturnPath($this->clampNotificationPage($request, ['view' => $returnTo === 'all' ? null : $returnTo] + $filters)))
                ->with('status', 'Notification marked as read.')
            : redirect('/account/notifications');
    }

    public function openNotification(Request $request, int $notification)
    {
        $target = DB::transaction(function () use ($request, $notification) {
            $item = $this->visibleNotifications($request)->where('notifications.id', $notification)
                ->select('notifications.id', 'notifications.reply_id', 'threads.id as thread_id')->lockForUpdate()->first();
            abort_unless($item, 404);
            DB::table('community_notifications')->where(['id' => $item->id, 'user_id' => $request->user()->id])
                ->whereNull('read_at')->update(['read_at' => now()]);

            return $item;
        }, attempts: 3);

        return redirect('/community/'.$target->thread_id.'/replies/'.$target->reply_id, 303, ['Cache-Control' => 'no-store']);
    }

    public function updateSelectedNotifications(Request $request)
    {
        $data = $request->validate([
            'notifications' => 'required|array|list|min:1|max:20',
            'notifications.*' => 'required|integer|distinct|min:1',
            'action' => 'required|in:read,unread',
            'view' => 'nullable|in:all,unread,read',
            'q' => 'nullable|string|max:100', 'period' => 'nullable|in:7,30,90', 'page' => 'nullable|integer|min:1',
        ]);
        DB::transaction(function () use ($request, $data) {
            $ids = $this->visibleNotifications($request)->whereIn('notifications.id', $data['notifications'])
                ->orderBy('notifications.id')->lockForUpdate()->pluck('notifications.id');
            abort_unless($ids->count() === count($data['notifications']), 404);
            $query = DB::table('community_notifications')->where('user_id', $request->user()->id)->whereIn('id', $ids);
            if ($data['action'] === 'read') {
                $query->whereNull('read_at')->update(['read_at' => now()]);
            } else {
                $query->whereNotNull('read_at')->update(['read_at' => null]);
            }
        }, attempts: 3);

        return redirect($this->notificationReturnPath($this->clampNotificationPage($request, ['view' => $data['view'] ?? 'all'] + $data)))
            ->with('status', 'Selected notifications marked '.$data['action'].'.');
    }

    public function readAllNotifications(Request $request)
    {
        // Only mark the current user's approved, visible replies on this portal.
        // Use bounded updates to avoid large SQL parameter lists.
        $visible = $this->visibleNotifications($request)->whereNull('notifications.read_at');
        $data = $request->validate(['cutoff' => 'required|integer|min:1', 'view' => 'nullable|in:all,unread,read',
            'q' => 'nullable|string|max:100', 'period' => 'nullable|in:7,30,90']);
        $latest = $data['cutoff'];
        if ($latest !== null) {
            $visible->where('notifications.id', '<=', $latest)->select('notifications.id as id')
                ->chunkById(250, function ($rows) use ($request) {
                    DB::table('community_notifications')->where('user_id', $request->user()->id)
                        ->whereIn('id', $rows->pluck('id')->all())->whereNull('read_at')
                        ->update(['read_at' => now()]);
                }, 'notifications.id', 'id');
        }

        return redirect($this->notificationReturnPath($data))
            ->with('status', 'All visible discussion notifications marked as read.');
    }

    private function matchingNotifications(Request $request, array $filters): Builder
    {
        $view = $filters['view'] ?? 'all';
        $matching = $this->visibleNotifications($request)
            ->when($view === 'unread', fn (Builder $query) => $query->whereNull('notifications.read_at'))
            ->when($view === 'read', fn (Builder $query) => $query->whereNotNull('notifications.read_at'))
            ->when($filters['period'] ?? null, fn ($query, $days) => $query->where('notifications.created_at', '>=', now()->subDays((int) $days)));
        foreach (KeywordSearch::patterns($filters['q'] ?? '') as $pattern) {
            $matching->whereRaw("LOWER(threads.title) LIKE ? ESCAPE '!'", [$pattern]);
        }

        return $matching;
    }

    private function clampNotificationPage(Request $request, array $filters): array
    {
        if (isset($filters['page'])) {
            $last = max(1, (int) ceil($this->matchingNotifications($request, $filters)->count() / self::NOTIFICATION_PAGE_SIZE));
            $page = min((int) $filters['page'], $last);
            unset($filters['page']);
            if ($page > 1) {
                $filters['page'] = $page;
            }
        }

        return $filters;
    }

    private function notificationReturnPath(array $data): string
    {
        $filters = array_intersect_key($data, array_flip(['view', 'q', 'period', 'page']));
        $query = http_build_query(array_filter($filters, fn ($value) => $value !== null && $value !== ''), '', '&', PHP_QUERY_RFC3986);

        return '/account/community-notifications'.($query === '' ? '' : '?'.$query);
    }

    private function visibleNotifications(Request $request): Builder
    {
        $site = $request->attributes->get('site');
        abort_unless($this->community->enabled($site, 'community'), 404);

        return DB::table('community_notifications as notifications')
            ->join('community_replies as replies', 'replies.id', '=', 'notifications.reply_id')
            ->join('community_threads as threads', 'threads.id', '=', 'replies.thread_id')
            ->join('users as actors', 'actors.id', '=', 'replies.author_id')
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
