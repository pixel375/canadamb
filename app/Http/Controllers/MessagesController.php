<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\User;
use App\Support\AccountActivity;
use App\Support\KeywordSearch;
use App\Support\Marketplace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MessagesController extends Controller
{
    public function index(Request $request)
    {
        $id = $request->user()->id;
        $filters = $request->validate(['view' => 'nullable|in:unread', 'q' => 'nullable|string|max:100']);
        $unread = fn ($messages) => $messages->where('sender_id', '!=', $id)
            ->whereRaw('messages.id > CASE WHEN conversations.buyer_id = ? THEN conversations.buyer_last_read_id ELSE conversations.seller_last_read_id END', [$id]);
        $threads = Conversation::forMember($id)
            ->with(['listing:id,title', 'buyer:id,name', 'seller:id,name', 'latestMessage'])
            ->withCount(['messages as unread' => $unread])
            ->addSelect(['blocked_by_me' => DB::table('user_blocks')->selectRaw('1')->where('user_id', $id)
                ->whereRaw('blocked_id = CASE WHEN conversations.buyer_id = ? THEN conversations.seller_id ELSE conversations.buyer_id END', [$id])->limit(1)])
            ->when(($filters['view'] ?? null) === 'unread', fn ($query) => $query->whereHas('messages', $unread))
            ->when(filled($filters['q'] ?? null), function ($query) use ($filters, $id) {
                foreach (KeywordSearch::patterns($filters['q']) as $pattern) {
                    $query->where(fn ($match) => $match
                        ->whereHas('listing', fn ($listing) => $listing->whereRaw("LOWER(title) LIKE ? ESCAPE '!'", [$pattern]))
                        ->orWhere(fn ($buyer) => $buyer->where('buyer_id', '!=', $id)
                            ->whereHas('buyer', fn ($user) => $user->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$pattern])))
                        ->orWhere(fn ($seller) => $seller->where('seller_id', '!=', $id)
                            ->whereHas('seller', fn ($user) => $user->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$pattern]))));
                }
            })
            ->latest('updated_at')->latest('id')->paginate(20)->withQueryString();

        return view('messages.index', ['threads' => $threads, 'member' => $id, 'filters' => $filters]);
    }

    public function start(Request $request, int $listing, Marketplace $marketplace)
    {
        $site = $request->attributes->get('site');
        abort_unless($marketplace->enabled($site), 404);
        $id = $request->user()->id;
        $data = $request->validate(['body' => 'required|string|max:2000']);
        $thread = DB::transaction(function () use ($site, $listing, $id, $data, $marketplace) {
            $item = $marketplace->visible($site)->lockForUpdate()->findOrFail($listing);
            abort_if($item->user_id === $id, 403);
            $this->lockParticipants($id, $item->user_id);
            abort_if($this->isBlocked($id, $item->user_id), 403, 'Messaging is blocked for this conversation.');
            $thread = Conversation::firstOrCreate(['buyer_id' => $id, 'listing_id' => $item->id], ['seller_id' => $item->user_id]);
            $this->write($thread, $id, $data['body']);

            return $thread;
        }, attempts: 3);

        return redirect('/account/messages/'.$thread->id);
    }

    public function show(Request $request, int $conversation)
    {
        $id = $request->user()->id;
        $thread = Conversation::forMember($id)->with(['listing:id,title', 'buyer:id,name', 'seller:id,name'])->findOrFail($conversation);
        $messages = $thread->messages()->with('sender:id,name')->latest('id')->paginate(30);
        if ($messages->onFirstPage() && $latest = $messages->first()?->id) {
            $marker = $thread->marker($id);
            Conversation::whereKey($thread->id)->where($marker, '<', $latest)->update([$marker => $latest, 'updated_at' => DB::raw('updated_at')]);
        }
        $mine = DB::table('user_blocks')->where(['user_id' => $id, 'blocked_id' => $thread->other($id)])->exists();

        return view('messages.show', ['thread' => $thread, 'messages' => $messages, 'blocked' => $this->isBlocked($id, $thread->other($id)), 'myBlock' => $mine]);
    }

    public function sync(Request $request, int $conversation, AccountActivity $activity)
    {
        $id = $request->user()->id;
        $thread = Conversation::forMember($id)->findOrFail($conversation);
        $data = $request->validate(['after' => 'required|integer|min:0', 'seen' => 'nullable|integer|min:0']);
        $after = (int) $data['after'];
        $seen = (int) ($data['seen'] ?? 0);
        if ($seen > 0 && $seen <= $after && $thread->messages()->whereKey($seen)->exists()) {
            $marker = $thread->marker($id);
            Conversation::whereKey($thread->id)->where($marker, '<', $seen)->update([$marker => $seen, 'updated_at' => $thread->updated_at]);
        }
        $messages = $thread->messages()->with('sender:id,name')->where('id', '>', $after)->orderBy('id')->limit(30)->get();

        return response()->json([
            'messages' => $messages->map(fn ($message) => [
                'id' => $message->id,
                'own' => $message->sender_id === $id,
                'sender' => $message->sender_id === $id ? 'You' : $message->sender->name,
                'body' => $message->body,
                'created_at' => $message->created_at->toIso8601String(),
                'time_label' => $message->created_at->diffForHumans(),
            ])->values(),
            'blocked' => $this->isBlocked($id, $thread->other($id)),
            'counts' => $activity->counts($request->user(), $request->attributes->get('site')),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function reply(Request $request, int $conversation)
    {
        $id = $request->user()->id;
        $data = $request->validate(['body' => 'required|string|max:2000']);
        DB::transaction(function () use ($id, $conversation, $data) {
            $thread = Conversation::forMember($id)->findOrFail($conversation);
            $this->lockParticipants($id, $thread->other($id));
            abort_if($this->isBlocked($id, $thread->other($id)), 403, 'Messaging is blocked for this conversation.');
            $this->write($thread, $id, $data['body']);
        }, attempts: 3);

        return redirect('/account/messages/'.$conversation);
    }

    public function blockedMembers(Request $request)
    {
        $members = User::whereIn('id', DB::table('user_blocks')->where('user_id', $request->user()->id)->select('blocked_id'))
            ->orderBy('name')->orderBy('id')->paginate(20, ['id', 'name']);

        return view('messages.blocked', compact('members'));
    }

    public function unblockMember(Request $request, int $member)
    {
        $id = $request->user()->id;
        DB::transaction(function () use ($id, $member) {
            $this->lockParticipants($id, $member);
            DB::table('user_blocks')->where(['user_id' => $id, 'blocked_id' => $member])->delete();
        }, attempts: 3);

        return redirect('/account/blocked-members')->with('status', 'Member unblocked.');
    }

    public function block(Request $request, int $conversation)
    {
        return $this->toggleBlock($request, $conversation, true);
    }

    public function unblock(Request $request, int $conversation)
    {
        return $this->toggleBlock($request, $conversation, false);
    }

    private function toggleBlock(Request $request, int $conversation, bool $block)
    {
        $id = $request->user()->id;
        DB::transaction(function () use ($id, $conversation, $block) {
            $thread = Conversation::forMember($id)->findOrFail($conversation);
            $other = $thread->other($id);
            $this->lockParticipants($id, $other);
            $data = ['user_id' => $id, 'blocked_id' => $other];
            if ($block) {
                DB::table('user_blocks')->insertOrIgnore($data);
            } else {
                DB::table('user_blocks')->where($data)->delete();
            }
        }, attempts: 3);

        return redirect('/account/messages/'.$conversation);
    }

    private function lockParticipants(int $id, int $other): void
    {
        User::whereIn('id', [$id, $other])->orderBy('id')->lockForUpdate()->get(['id']);
    }

    private function isBlocked(int $id, int $other): bool
    {
        return DB::table('user_blocks')->where(fn ($query) => $query
            ->where(['user_id' => $id, 'blocked_id' => $other])->orWhere(['user_id' => $other, 'blocked_id' => $id]))->exists();
    }

    private function write(Conversation $thread, int $id, string $body): void
    {
        $message = $thread->messages()->create(['sender_id' => $id, 'body' => $body]);
        $marker = $thread->marker($id);
        Conversation::whereKey($thread->id)->where($marker, '<', $message->id)->update([$marker => $message->id]);
    }
}
