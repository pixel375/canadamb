<?php

namespace App\Http\Controllers;

use App\Models\CommunityReply;
use App\Models\CommunityThread;
use App\Models\Location;
use App\Support\ApprovalPolicy;
use App\Support\Community;
use App\Support\LocationScope;
use App\Support\NetworkUrl;
use App\Support\NotificationInbox;
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

        return view('community.forum', compact('threads', 'filters'));
    }

    public function thread(Request $request, int $thread)
    {
        $item = $this->visible($request)->findOrFail($thread);
        $site = $request->attributes->get('site');
        $canonical = NetworkUrl::to($this->community->canonicalSite($item, 'community'), '/community/'.$item->id);
        if (parse_url($canonical, PHP_URL_HOST) !== $site->domain->host) {
            return redirect()->away($canonical, 301, ['Cache-Control' => 'no-store']);
        }

        $replies = $item->replies()->where('status', 'approved')->with('author')->oldest('id')->paginate(Community::REPLIES_PER_PAGE);
        $following = $request->user() && DB::table('community_thread_follows')
            ->where(['thread_id' => $thread, 'user_id' => $request->user()->id])->exists();

        return view('community.thread', compact('item', 'canonical', 'replies', 'following'));
    }

    public function showReply(Request $request, int $reply)
    {
        $post = CommunityReply::where('status', 'approved')->findOrFail($reply);
        $thread = $this->visible($request)->findOrFail($post->thread_id);
        $site = $this->community->canonicalSite($thread, 'community');

        return redirect()->away(NetworkUrl::to($site, $this->community->replyPath($thread, $reply)));
    }

    public function create(Request $request)
    {
        $site = $request->attributes->get('site');
        abort_unless($this->community->enabled($site, 'community'), 404);

        return view('community.new-thread', [
            'places' => $site->isNetwork() ? Location::selectable()->orderBy('name')->get() : collect(),
        ]);
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
        $approved = DB::transaction(function () use ($request, $site, $data, $policy) {
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

            return $revision !== null;
        }, attempts: 3);

        return redirect('/community')->with('status', $approved ? 'Discussion published.' : 'Discussion submitted for review.');
    }

    public function reply(Request $request, int $thread, ApprovalPolicy $policy)
    {
        $data = $request->validate(['body' => 'required|string|min:5|max:3000']);
        $site = $request->attributes->get('site');
        $approved = DB::transaction(function () use ($request, $thread, $data, $site, $policy) {
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

            return $revision !== null;
        }, attempts: 3);

        return redirect('/community/'.$thread)->with('status', $approved ? 'Reply published.' : 'Reply submitted for review.');
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

    private function visible(Request $request)
    {
        $site = $request->attributes->get('site');
        abort_unless($this->community->enabled($site, 'community'), 404);

        return $this->community->threads($site->location_id);
    }
}
