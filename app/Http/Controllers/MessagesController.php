<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\Conversation;
use App\Models\JobPosting;
use App\Models\Message;
use App\Models\User;
use App\Support\AccountActivity;
use App\Support\BusinessDirectory;
use App\Support\BusinessInquirySettings;
use App\Support\JobBoard;
use App\Support\KeywordSearch;
use App\Support\ListingPhotos;
use App\Support\Marketplace;
use App\Support\MessageAttachments;
use App\Support\MessagingExtensions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class MessagesController extends Controller
{
    public function index(Request $request)
    {
        $id = $request->user()->id;
        $filters = $request->validate(['view' => 'nullable|in:unread,archived', 'q' => 'nullable|string|max:100']);
        $unread = fn ($messages) => $messages->where('sender_id', '!=', $id)
            ->whereRaw('messages.id > CASE WHEN conversations.buyer_id = ? THEN conversations.buyer_last_read_id ELSE conversations.seller_last_read_id END', [$id]);
        $extended = MessagingExtensions::enabled();
        abort_if(($filters['view'] ?? null) === 'archived' && ! $extended, 404);
        $threads = Conversation::forMember($id)
            ->when($extended, function ($query) use ($filters, $id) {
                $column = 'CASE WHEN buyer_id = ? THEN buyer_archived_at ELSE seller_archived_at END';
                $query->whereRaw($column.(($filters['view'] ?? null) === 'archived' ? ' IS NOT NULL' : ' IS NULL'), [$id]);
            })
            ->with(['listing:id,title', 'buyer:id,name', 'seller:id,name', 'latestMessage'])
            ->withCount(['messages as unread' => $unread])
            ->addSelect(['blocked_by_me' => DB::table('user_blocks')->selectRaw('1')->where('user_id', $id)
                ->whereRaw('blocked_id = CASE WHEN conversations.buyer_id = ? THEN conversations.seller_id ELSE conversations.buyer_id END', [$id])->limit(1)])
            ->when(($filters['view'] ?? null) === 'unread', fn ($query) => $query->whereHas('messages', $unread))
            ->when(filled($filters['q'] ?? null), function ($query) use ($filters, $id, $extended) {
                foreach (KeywordSearch::patterns($filters['q']) as $pattern) {
                    $query->where(fn ($match) => $match
                        ->whereHas('listing', fn ($listing) => $listing->whereRaw("LOWER(title) LIKE ? ESCAPE '!'", [$pattern]))
                        ->when($extended, fn ($context) => $context->orWhereRaw("LOWER(inquiry_title) LIKE ? ESCAPE '!'", [$pattern]))
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

    public function startBusiness(Request $request, int $business)
    {
        abort_unless(MessagingExtensions::enabled(), 404);
        $site = $request->attributes->get('site');
        $directory = app(BusinessDirectory::class);
        abort_unless($directory->enabled($site), 404);
        $data = $request->validate(['body' => 'required|string|max:2000']);
        $thread = DB::transaction(function () use ($request, $site, $directory, $business, $data) {
            $item = $directory->visible($site)->lockForUpdate()->findOrFail($business);

            return $this->startInquiry($item, null, $request->user()->id, $data['body']);
        }, attempts: 3);

        return redirect('/account/messages/'.$thread->id);
    }

    public function startJob(Request $request, int $job)
    {
        abort_unless(MessagingExtensions::enabled(), 404);
        $site = $request->attributes->get('site');
        $board = app(JobBoard::class);
        abort_unless($board->enabled($site), 404);
        $data = $request->validate(['body' => 'required|string|max:2000']);
        $thread = DB::transaction(function () use ($request, $site, $board, $job, $data) {
            $initial = $board->visible($site->location_id)->findOrFail($job);
            $business = Business::lockForUpdate()->findOrFail($initial->branch->business_id);
            $item = $board->visible($site->location_id)->lockForUpdate()->findOrFail($job);
            abort_unless($item->branch->business_id === $business->id, 409);

            return $this->startInquiry($business, $item, $request->user()->id, $data['body']);
        }, attempts: 3);

        return redirect('/account/messages/'.$thread->id);
    }

    private function startInquiry(Business $business, ?JobPosting $job, int $id, string $body): Conversation
    {
        $owners = $business->members()->wherePivot('role', 'owner')->get(['users.id', 'users.email_verified_at']);
        abort_unless($owners->count() === 1 && $owners->first()->hasVerifiedEmail() && $business->status === 'active', 404);
        $recipient = $owners->first()->id;
        abort_unless(BusinessInquirySettings::accepts($business, $job !== null), 404);
        abort_if($business->members()->whereKey($id)->exists(), 403);
        $this->lockParticipants($id, $recipient);
        abort_if($this->isBlocked($id, $recipient), 403, 'Messaging is blocked for this conversation.');
        $key = hash('sha256', implode(':', [$job ? 'job' : 'business', $job?->id ?? $business->id, $id, $recipient]));
        $thread = Conversation::firstOrCreate(['inquiry_key' => $key], [
            'buyer_id' => $id, 'seller_id' => $recipient, 'inquiry_business_id' => $business->id,
            'inquiry_job_id' => $job?->id, 'inquiry_title' => $job?->title ?? $business->name,
        ]);
        $this->write($thread, $id, $body);

        return $thread;
    }

    public function archive(Request $request, int $conversation)
    {
        return $this->toggleArchive($request, $conversation, true);
    }

    public function unarchive(Request $request, int $conversation)
    {
        return $this->toggleArchive($request, $conversation, false);
    }

    private function toggleArchive(Request $request, int $conversation, bool $archive)
    {
        abort_unless(MessagingExtensions::enabled(), 404);
        $id = $request->user()->id;
        DB::transaction(function () use ($id, $conversation, $archive) {
            $thread = Conversation::forMember($id)->lockForUpdate()->findOrFail($conversation);
            $marker = $thread->archiveMarker($id);
            if ($archive) {
                $thread->forceFill([$marker => now(), $thread->marker($id) => max($thread->{$thread->marker($id)}, $thread->messages()->max('id') ?? 0)])->save();
            } else {
                $thread->forceFill([$marker => null])->save();
            }
        }, attempts: 3);

        return redirect('/account/messages'.($archive ? '?view=archived' : ''))->with('status', $archive ? 'Conversation archived and marked read.' : 'Conversation restored.');
    }

    public function show(Request $request, int $conversation)
    {
        $id = $request->user()->id;
        $thread = Conversation::forMember($id)->with(['listing:id,title', 'buyer:id,name', 'seller:id,name'])->findOrFail($conversation);
        $messages = $thread->messages()->with('sender:id,name')
            ->when(MessageAttachments::enabled(), fn ($query) => $query->withExists('attachment'))
            ->latest('id')->paginate(30);
        if ($messages->onFirstPage() && $latest = $messages->first()?->id) {
            $marker = $thread->marker($id);
            Conversation::whereKey($thread->id)->where($marker, '<', $latest)->update([$marker => $latest, 'updated_at' => DB::raw('updated_at')]);
        }
        $mine = DB::table('user_blocks')->where(['user_id' => $id, 'blocked_id' => $thread->other($id)])->exists();

        return view('messages.show', ['thread' => $thread, 'messages' => $messages, 'blocked' => $this->isBlocked($id, $thread->other($id)) || ! MessagingExtensions::canReply($thread), 'myBlock' => $mine]);
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
        $messages = $thread->messages()->with('sender:id,name')
            ->when(MessageAttachments::enabled(), fn ($query) => $query->withExists('attachment'))
            ->where('id', '>', $after)->orderBy('id')->limit(30)->get();

        return response()->json([
            'messages' => $messages->map(fn ($message) => [
                'id' => $message->id,
                'own' => $message->sender_id === $id,
                'sender' => $message->sender_id === $id ? 'You' : $message->sender->name,
                'body' => $message->body,
                'has_attachment' => (bool) $message->attachment_exists,
                'created_at' => $message->created_at->toIso8601String(),
                'time_label' => $message->created_at->diffForHumans(),
            ])->values(),
            'blocked' => $this->isBlocked($id, $thread->other($id)) || ! MessagingExtensions::canReply($thread),
            'counts' => $activity->counts($request->user(), $request->attributes->get('site')),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function reply(Request $request, int $conversation, ListingPhotos $photos)
    {
        $id = $request->user()->id;
        $thread = Conversation::forMember($id)->findOrFail($conversation);
        $enabled = MessageAttachments::enabled();
        $data = $request->validate(['body' => 'required|string|max:2000', 'photo' => $enabled ? 'nullable|file|mimes:jpg,jpeg,png,webp|max:4096' : 'prohibited']);
        abort_if($this->isBlocked($id, $thread->other($id)) || ! MessagingExtensions::canReply($thread), 403);
        $media = [];
        try {
            if ($request->hasFile('photo')) {
                if (MessageAttachments::quotaExceeded($id, $conversation)) {
                    throw ValidationException::withMessages(['photo' => 'Photo attachment limit reached.']);
                }
                $media = $photos->store([$request->file('photo')], 'attachments', MessageAttachments::DISK);
            }
            DB::transaction(function () use ($id, $conversation, $data, $media) {
                $thread = Conversation::forMember($id)->findOrFail($conversation);
                if ($thread->inquiry_business_id) {
                    Business::lockForUpdate()->findOrFail($thread->inquiry_business_id);
                }
                $this->lockParticipants($id, $thread->other($id));
                abort_if($this->isBlocked($id, $thread->other($id)) || ! MessagingExtensions::canReply($thread), 403, 'Messaging is blocked for this conversation.');
                if ($media && (! MessageAttachments::enabled() || MessageAttachments::quotaExceeded($id, $conversation))) {
                    throw ValidationException::withMessages(['photo' => 'Photo attachments are unavailable or the limit has been reached.']);
                }
                $message = $this->write($thread, $id, $data['body']);
                if ($media) {
                    $message->attachment()->create([
                        'image_path' => $media[0]['image_path'], 'thumbnail_path' => $media[0]['thumbnail_path'],
                    ]);
                }
            }, attempts: 3);
        } catch (Throwable $exception) {
            $photos->delete($media, MessageAttachments::DISK);
            throw $exception;
        }

        return redirect('/account/messages/'.$conversation);
    }

    public function attachment(Request $request, int $conversation, int $message)
    {
        abort_unless(MessageAttachments::enabled(), 404);
        $thread = Conversation::forMember($request->user()->id)->findOrFail($conversation);
        $item = $thread->messages()->findOrFail($message)->attachment()->firstOrFail();
        abort_unless(preg_match('/^attachments\/[a-f0-9-]{36}\.webp$/D', $item->image_path), 404);
        $disk = Storage::disk(MessageAttachments::DISK);
        abort_unless($disk->exists($item->image_path), 404);

        return $disk->download($item->image_path, 'canadamb-photo.webp', [
            'Content-Type' => 'image/webp', 'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff', 'X-Robots-Tag' => 'noindex, nofollow',
        ]);
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

    private function write(Conversation $thread, int $id, string $body): Message
    {
        $thread = Conversation::lockForUpdate()->findOrFail($thread->id);
        $message = $thread->messages()->create(['sender_id' => $id, 'body' => $body]);
        $marker = $thread->marker($id);
        $changes = [$marker => $message->id];
        if (MessagingExtensions::enabled()) {
            $changes += ['buyer_archived_at' => null, 'seller_archived_at' => null];
        }
        Conversation::whereKey($thread->id)->where($marker, '<', $message->id)->update($changes);

        return $message;
    }
}
