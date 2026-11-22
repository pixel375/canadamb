<?php

namespace App\Http\Controllers;

use App\Models\CommunityReply;
use App\Models\CommunityThread;
use App\Models\Location;
use App\Models\Site;
use App\Support\ApprovalPolicy;
use App\Support\Community;
use App\Support\LocationScope;
use App\Support\NetworkUrl;
use App\Support\NotificationInbox;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CommunityForumController extends Controller
{
    public function __construct(private readonly Community $community) {}

    public function index(Request $request)
    {
        $site = $request->attributes->get('site');
        abort_unless($this->community->enabled($site, 'community'), 404);
        $filters = $request->validate($this->filterRules($site));
        $threads = $this->community->threads($site->location_id ?? (isset($filters['location']) ? (int) $filters['location'] : null))
            ->when(isset($filters['q']), function ($query) use ($filters) {
                $term = strtr($filters['q'], ['!' => '!!', '%' => '!%', '_' => '!_']);
                $query->whereRaw("title LIKE ? ESCAPE '!'", ["%$term%"]);
            })
            ->when($filters['category'] ?? null, fn ($query, $category) => $query->where('category', $category))
            ->orderByDesc('pinned')->orderByDesc(($filters['sort'] ?? 'activity') === 'newest' ? 'created_at' : 'last_activity_at')
            ->orderByDesc('id')->paginate(18)->appends($filters);
        $places = $site->isNetwork() ? Location::selectable()->orderBy('name')->get(['id', 'name']) : collect();
        $canonical = NetworkUrl::to($site, Community::DISCUSSIONS_PATH);

        return view('community.forum', compact('threads', 'filters', 'places', 'canonical'));
    }

    public function thread(Request $request, int $thread)
    {
        $item = $this->visible($request)->findOrFail($thread);
        $canonical = NetworkUrl::to($this->community->canonicalSite($item, 'community'), Community::DISCUSSIONS_PATH.'/'.$item->id);
        $replies = $item->replies()->where('status', 'approved')->with('author')->oldest('id')->paginate(Community::REPLIES_PER_PAGE);
        $following = $request->user() && DB::table('community_thread_follows')
            ->where(['thread_id' => $thread, 'user_id' => $request->user()->id])->exists();

        return view('community.thread', compact('item', 'canonical', 'replies', 'following'));
    }

    public function showReply(Request $request, int $reply)
    {
        $thread = $this->visibleReplyThread($request, $reply);

        return redirect($this->community->replyPath($thread, $reply), headers: ['Cache-Control' => 'no-store']);
    }

    public function legacyIndex(Request $request)
    {
        $site = $request->attributes->get('site');
        abort_unless($this->community->enabled($site, 'community'), 404);

        return $this->legacyRedirect($request, Community::DISCUSSIONS_PATH, $this->filterRules($site));
    }

    public function legacyThread(Request $request, int $thread)
    {
        abort_unless($this->visible($request)->whereKey($thread)->exists(), 404);

        return $this->legacyRedirect($request, Community::DISCUSSIONS_PATH.'/'.$thread, ['page' => 'integer|min:1|max:100000']);
    }

    public function legacyReply(Request $request, int $reply)
    {
        $this->visibleReplyThread($request, $reply);

        return $this->legacyRedirect($request, Community::DISCUSSIONS_PATH.'/replies/'.$reply);
    }

    public function legacyCreate(Request $request)
    {
        abort_unless($this->community->enabled($request->attributes->get('site'), 'community'), 404);

        return $this->legacyRedirect($request, Community::DISCUSSIONS_PATH.'/new');
    }

    public function create(Request $request)
    {
        $site = $request->attributes->get('site');
        abort_unless($this->community->enabled($site, 'community'), 404);

        return view('community.new-thread', [
            'places' => $site->isNetwork() ? Location::selectable()->orderBy('name')->get() : collect(),
        ]);
    }

    public function participate(Request $request, int $thread)
    {
        $item = $this->visible($request)->findOrFail($thread);

        return redirect(Community::DISCUSSIONS_PATH.'/'.$item->id.($item->locked ? '' : '#discussion-reply'));
    }

    public function store(Request $request, ApprovalPolicy $policy)
    {
        $site = $request->attributes->get('site');
        abort_unless($this->community->enabled($site, 'community'), 404);
        $data = $request->validate([
            'title' => 'required|string|max:140', 'body' => 'required|string|min:10|max:5000',
            'category' => ['required', Rule::in(array_keys(Community::FORUMS))], 'location_id' => 'nullable|integer',
        ]);
        $requested = isset($data['location_id']) ? (int) $data['location_id'] : null;
        $data['location_id'] = $site->isNetwork() && $requested === null ? null : LocationScope::forPublication($site, $requested);
        $item = DB::transaction(function () use ($request, $site, $data, $policy) {
            $author = $request->user()->id;
            $item = new CommunityThread($data + ['author_id' => $author]);
            $revision = $policy->automaticRevision($site, 'thread', $item->location_id);
            if ($revision !== null) {
                $item->forceFill(['status' => 'approved', 'last_activity_at' => now()]);
            }
            $item->save();
            if ($revision !== null) {
                $policy->recordAutomaticApproval('thread', $item->id, $author, $site, $revision, $item->location_id);
            }

            return $item;
        }, attempts: 3);

        return $item->status === 'approved'
            ? redirect(Community::DISCUSSIONS_PATH.'/'.$item->id)->with('status', 'Discussion published.')
            : redirect('/account/discussions/'.$item->id.'/preview')->with('status', 'Discussion submitted for review.');
    }

    public function reply(Request $request, int $thread, ApprovalPolicy $policy)
    {
        $data = $request->validate(['body' => 'required|string|min:5|max:3000']);
        $site = $request->attributes->get('site');
        $publishedReply = DB::transaction(function () use ($request, $thread, $data, $site, $policy) {
            $item = $this->visible($request)->lockForUpdate()->findOrFail($thread);
            abort_if($item->locked, 409, 'This discussion is locked.');
            $author = $request->user()->id;
            $reply = $item->replies()->make($data + ['author_id' => $author]);
            $revision = $policy->automaticRevision($site, 'reply', $item->location_id);
            if ($revision !== null) {
                $reply->forceFill(['status' => 'approved']);
                $item->forceFill(['last_activity_at' => now()])->save();
            }
            $reply->save();
            if ($revision !== null) {
                $policy->recordAutomaticApproval('reply', $reply->id, $author, $site, $revision, $item->location_id);
                NotificationInbox::recordFollowedReply($item->id, $reply->id, $author, $item->location_id);
            }

            return $revision === null ? null : $reply->id;
        }, attempts: 3);

        return $publishedReply === null
            ? redirect(Community::DISCUSSIONS_PATH.'/'.$thread)->with('status', 'Reply submitted for review.')
            : redirect(Community::DISCUSSIONS_PATH.'/replies/'.$publishedReply)->with('status', 'Reply published.');
    }

    public function follow(Request $request, int $thread)
    {
        DB::transaction(function () use ($request, $thread) {
            $item = $this->visible($request)->lockForUpdate()->findOrFail($thread);
            DB::table('community_thread_follows')->insertOrIgnore(['thread_id' => $item->id, 'user_id' => $request->user()->id]);
        }, attempts: 3);

        return redirect(Community::DISCUSSIONS_PATH.'/'.$thread)->with('status', 'You are following this discussion.');
    }

    public function unfollow(Request $request, int $thread)
    {
        DB::transaction(function () use ($request, $thread) {
            $item = $this->visible($request)->lockForUpdate()->findOrFail($thread);
            DB::table('community_thread_follows')->where(['thread_id' => $item->id, 'user_id' => $request->user()->id])->delete();
        }, attempts: 3);

        return redirect(Community::DISCUSSIONS_PATH.'/'.$thread)->with('status', 'You stopped following this discussion.');
    }

    private function filterRules(Site $site): array
    {
        return [
            'q' => 'nullable|string|max:100',
            'category' => ['nullable', Rule::in(array_keys(Community::FORUMS))],
            'location' => $site->isNetwork()
                ? ['nullable', 'integer', Rule::exists('locations', 'id')->where('province', 'MB')->where('selectable', true)]
                : ['prohibited'],
            'sort' => 'nullable|in:activity,newest',
            'page' => 'nullable|integer|min:1|max:100000',
        ];
    }

    private function legacyRedirect(Request $request, string $path, array $rules = [])
    {
        $query = [];
        foreach ($rules as $key => $rule) {
            $value = $request->query($key);
            if ($value !== null && Validator::make([$key => $value], [$key => $rule])->passes()) {
                $query[$key] = $value;
            }
        }

        $suffix = $query === [] ? '' : '?'.http_build_query($query, encoding_type: PHP_QUERY_RFC3986);

        return redirect($path.$suffix, 301, ['Cache-Control' => 'no-store']);
    }

    private function visibleReplyThread(Request $request, int $reply): CommunityThread
    {
        $visible = $this->visible($request);
        $post = CommunityReply::where('status', 'approved')->findOrFail($reply, ['id', 'thread_id']);

        return $visible->findOrFail($post->thread_id);
    }

    private function visible(Request $request)
    {
        $site = $request->attributes->get('site');
        abort_unless($this->community->enabled($site, 'community'), 404);

        return $this->community->threads($site->location_id);
    }
}
