<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\Conversation;
use App\Models\JobPosting;
use App\Models\Message;
use App\Models\User;
use App\Support\AccountActivity;
use App\Support\Audit;
use App\Support\BusinessDirectory;
use App\Support\BusinessInquirySettings;
use App\Support\ContentReports;
use App\Support\DirectMessaging;
use App\Support\JobBoard;
use App\Support\KeywordSearch;
use App\Support\ListingPhotos;
use App\Support\Marketplace;
use App\Support\MessageAttachments;
use App\Support\MessageDocuments;
use App\Support\MessagingExtensions;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class MessagesController extends Controller
{
    public function index(Request $request)
    {
        $id = $request->user()->id;
        $filters = $request->validate(['view' => 'nullable|in:unread,archived', 'q' => 'nullable|string|max:100', 'kind' => 'nullable|in:marketplace,business,job,member,official',
            'role' => 'nullable|in:sent,received', 'sort' => 'nullable|in:recent,oldest']);
        $unread = fn ($messages) => $messages->where('sender_id', '!=', $id)
            ->whereRaw('messages.id > CASE WHEN conversations.buyer_id = ? THEN conversations.buyer_last_read_id ELSE conversations.seller_last_read_id END', [$id]);
        $extended = MessagingExtensions::enabled();
        abort_if(! $extended && (($filters['view'] ?? null) === 'archived' || in_array($filters['kind'] ?? null, ['business', 'job'], true)), 404);
        abort_if(! DirectMessaging::enabled() && in_array($filters['kind'] ?? null, ['member', 'official'], true), 404);
        $threads = Conversation::forMember($id)
            ->when(($filters['role'] ?? null) === 'sent', fn ($query) => $query->where('buyer_id', $id))
            ->when(($filters['role'] ?? null) === 'received', fn ($query) => $query->where('seller_id', $id))
            ->when(($filters['kind'] ?? null) === 'marketplace', fn ($query) => $query->whereNotNull('listing_id'))
            ->when(($filters['kind'] ?? null) === 'business', fn ($query) => $query->whereNull('listing_id')->whereNotNull('inquiry_business_id')->whereNull('inquiry_job_id'))
            ->when(($filters['kind'] ?? null) === 'job', fn ($query) => $query->whereNull('listing_id')->whereNotNull('inquiry_job_id'))
            ->when(in_array($filters['kind'] ?? null, ['member', 'official'], true), fn ($query) => $query->where('direct_kind', $filters['kind']))
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
            ->orderBy('updated_at', ($filters['sort'] ?? '') === 'oldest' ? 'asc' : 'desc')
            ->orderBy('id', ($filters['sort'] ?? '') === 'oldest' ? 'asc' : 'desc')->paginate(10)->withQueryString();

        $unreadThreads = $this->activeInbox($id)->whereHas('messages', $unread);
        $readCutoff = Message::whereIn('conversation_id', $unreadThreads->select('id'))->where('sender_id', '!=', $id)->max('id');

        return response()->view('messages.index', ['threads' => $threads, 'member' => $id, 'filters' => $filters, 'readCutoff' => $readCutoff])
            ->header('Cache-Control', 'private, no-store');
    }

    private function activeInbox(int $member)
    {
        return Conversation::forMember($member)->when(MessagingExtensions::enabled(), fn ($query) => $query
            ->whereRaw('CASE WHEN buyer_id = ? THEN buyer_archived_at ELSE seller_archived_at END IS NULL', [$member]));
    }

    public function readAll(Request $request)
    {
        $data = $request->validate(['cutoff' => 'required|integer|min:1', 'confirm_read' => 'accepted']);
        $member = $request->user()->id;
        $cutoff = (int) $data['cutoff'];
        $incoming = fn ($query) => $query->where('sender_id', '!=', $member)->where('id', '<=', $cutoff);
        $this->activeInbox($member)->whereHas('messages', fn ($query) => $incoming($query)
            ->whereRaw('messages.id > CASE WHEN conversations.buyer_id = ? THEN conversations.buyer_last_read_id ELSE conversations.seller_last_read_id END', [$member]))
            ->withMax(['messages as seen_through' => $incoming], 'id')
            ->chunkById(100, function ($threads) use ($member) {
                foreach ($threads as $thread) {
                    $marker = $thread->marker($member);
                    DB::table('conversations')->where('id', $thread->id)->where($marker, '<', $thread->seen_through)
                        ->update([$marker => $thread->seen_through]);
                }
            });

        return redirect('/account/messages?view=unread')->with('status', 'Messages received before the inbox was opened have been marked read. Newer messages remain unread.');
    }

    public function preferences(Request $request)
    {
        abort_unless(DirectMessaging::enabled(), 404);
        $data = $request->validate(['member_messages' => 'required|boolean', 'official_messages' => 'required|boolean', 'version' => 'required|string|size:64']);
        $id = $request->user()->id;
        DB::transaction(function () use ($id, $data) {
            User::lockForUpdate()->findOrFail($id);
            if (! hash_equals(DirectMessaging::version(DirectMessaging::preferences($id)), $data['version'])) {
                throw ValidationException::withMessages(['version' => 'Your messaging preferences changed. Reload settings before saving.']);
            }
            DB::table('direct_message_preferences')->updateOrInsert(['user_id' => $id], [
                'member_messages' => (bool) $data['member_messages'], 'official_messages' => (bool) $data['official_messages'], 'updated_at' => now(),
            ]);
        }, attempts: 3);

        return redirect('/account/settings')->with('status', 'Direct messaging preferences saved.');
    }

    public function composeDirect(Request $request, int $member, string $kind)
    {
        abort_unless(DirectMessaging::enabled(), 404);
        $recipient = User::findOrFail($member);
        $sender = $request->user();
        $key = DirectMessaging::key($sender->id, $member, $kind);
        $existing = Conversation::forMember($sender->id)->where('inquiry_key', $key)->first();
        if ($existing) {
            return redirect('/account/messages/'.$existing->id);
        }
        abort_unless(DirectMessaging::allows($sender, $recipient, $kind) && ! $this->isBlocked($sender->id, $member), 404);

        return response()->view('messages.compose-direct', ['recipient' => $recipient, 'kind' => $kind])
            ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function startDirect(Request $request, int $member, string $kind)
    {
        abort_unless(DirectMessaging::enabled(), 404);
        $data = $request->validate(['body' => 'required|string|max:2000']);
        $id = $request->user()->id;
        $thread = DB::transaction(function () use ($request, $member, $kind, $data, $id) {
            $this->lockParticipants($id, $member);
            $sender = User::findOrFail($id);
            $recipient = User::findOrFail($member);
            $key = DirectMessaging::key($id, $member, $kind);
            $existing = Conversation::forMember($id)->where('inquiry_key', $key)->first();
            if ($existing) {
                return $existing;
            }
            abort_unless(DirectMessaging::allows($sender, $recipient, $kind) && ! $this->isBlocked($id, $member), 404);
            abort_if(Conversation::where('buyer_id', $id)->whereNotNull('direct_kind')->where('created_at', '>=', now()->subDay())->count() >= 10, 429, 'New conversation limit reached.');
            $thread = Conversation::create([
                'inquiry_key' => $key, 'direct_kind' => $kind, 'buyer_id' => $id, 'seller_id' => $member,
                'inquiry_title' => $kind === 'official' ? 'Official CanadaMB conversation' : 'Direct member conversation',
            ]);
            $this->write($thread, $id, $data['body']);
            if ($kind === 'official') {
                Audit::record('message.official-started', $id, $request->attributes->get('site')->id, ['conversation_id' => $thread->id]);
            }

            return $thread;
        }, attempts: 3);

        return redirect('/account/messages/'.$thread->id);
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
        abort_unless(User::whereKey($recipient)->whereNotNull('email_verified_at')->exists(), 404);
        abort_if($this->isBlocked($id, $recipient), 403, 'Messaging is blocked for this conversation.');
        $key = hash('sha256', implode(':', [$job ? 'job' : 'business', $job?->id ?? $business->id, $id, $recipient]));
        $thread = Conversation::firstOrCreate(['inquiry_key' => $key], [
            'buyer_id' => $id, 'seller_id' => $recipient, 'inquiry_business_id' => $business->id,
            'inquiry_job_id' => $job?->id, 'inquiry_title' => $job?->title ?? $business->name,
        ]);
        abort_unless(MessagingExtensions::canReply($thread), 403);
        $this->write($thread, $id, $body);

        return $thread;
    }

    public function archiveSelected(Request $request)
    {
        abort_unless(MessagingExtensions::enabled(), 404);
        $data = $request->validate([
            'conversations' => 'required|array|list|min:1|max:10', 'conversations.*' => 'required|integer|distinct|min:1',
            'versions' => 'required|array|max:20', 'versions.*' => 'required|string|size:64',
            'action' => 'required|in:archive,restore', 'confirm_selection' => 'accepted',
        ]);
        $id = $request->user()->id;
        $archive = $data['action'] === 'archive';
        DB::transaction(function () use ($id, $data, $archive) {
            $threads = Conversation::forMember($id)->whereIn('id', $data['conversations'])->orderBy('id')->lockForUpdate()->get();
            abort_unless($threads->count() === count($data['conversations']), 404);
            $latest = [];
            foreach ($threads as $thread) {
                $latest[$thread->id] = (int) $thread->messages()->max('id');
                if (! isset($data['versions'][$thread->id]) || ! hash_equals($thread->archiveVersion($id, $latest[$thread->id]), $data['versions'][$thread->id])
                    || ($thread->{$thread->archiveMarker($id)} === null) !== $archive) {
                    throw ValidationException::withMessages(['conversations' => 'A selected conversation changed. Reload the inbox before continuing. No conversations changed.']);
                }
            }
            foreach ($threads as $thread) {
                $changes = [$thread->archiveMarker($id) => $archive ? now() : null];
                if ($archive) {
                    $marker = $thread->marker($id);
                    $changes[$marker] = max($thread->{$marker}, $latest[$thread->id]);
                }
                DB::table('conversations')->where('id', $thread->id)->update($changes);
            }
        }, attempts: 3);

        return redirect('/account/messages'.($archive ? '?view=archived' : ''))->with('status', count($data['conversations']).($archive ? ' conversations archived and marked read.' : ' conversations restored.'));
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
                $changes = [$marker => now(), $thread->marker($id) => max($thread->{$thread->marker($id)}, $thread->messages()->max('id') ?? 0)];
            } else {
                $changes = [$marker => null];
            }
            DB::table('conversations')->where('id', $conversation)->update($changes);
        }, attempts: 3);

        return redirect('/account/messages'.($archive ? '?view=archived' : ''))->with('status', $archive ? 'Conversation archived and marked read.' : 'Conversation restored.');
    }

    public function show(Request $request, int $conversation)
    {
        $id = $request->user()->id;
        $thread = Conversation::forMember($id)->with(['listing:id,title', 'buyer:id,name', 'seller:id,name'])->findOrFail($conversation);
        $request->session()->forget('conversation_history_search');
        $data = $request->validate(['history' => 'nullable|string|max:512']);
        $encoded = $data['history'] ?? null;
        $cursor = null;
        if ($encoded !== null) {
            $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);
            $parameters = $decoded !== false ? json_decode($decoded, true) : null;
            abort_unless(is_array($parameters) && is_int($parameters['id'] ?? null) && $parameters['id'] > 0
                && is_bool($parameters['_pointsToNextItems'] ?? null), 404);
            $cursor = new Cursor(['id' => $parameters['id']], $parameters['_pointsToNextItems']);
        }
        $messages = $thread->messages()->with('sender:id,name')
            ->when(MessageAttachments::enabled(), fn ($query) => $query->withExists('attachment'))
            ->when(MessageDocuments::enabled(), fn ($query) => $query->withExists('document'))
            ->latest('id')->cursorPaginate(30, ['*'], 'history', $cursor);
        $latestPage = $cursor === null;
        if ($latestPage && $latest = $messages->first()?->id) {
            $marker = $thread->marker($id);
            Conversation::whereKey($thread->id)->where($marker, '<', $latest)->update([$marker => $latest, 'updated_at' => DB::raw('updated_at')]);
        }
        $mine = DB::table('user_blocks')->where(['user_id' => $id, 'blocked_id' => $thread->other($id)])->exists();
        $context = $this->publicContext($request, $thread);
        $reportMessage = $request->user()->hasVerifiedEmail() && ContentReports::enabled()
            ? $thread->messages()->where('sender_id', '!=', $id)->latest('id')->value('id') : null;

        return response()->view('messages.show', ['thread' => $thread, 'messages' => $messages, 'blocked' => $this->isBlocked($id, $thread->other($id)) || ! MessagingExtensions::canReply($thread), 'myBlock' => $mine,
            'context' => $context, 'reportMessage' => $reportMessage, 'latestPage' => $latestPage])->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    private function publicContext(Request $request, Conversation $thread): ?array
    {
        $site = $request->attributes->get('site');
        if ($thread->listing_id) {
            $marketplace = app(Marketplace::class);
            $item = $marketplace->enabled($site) ? $marketplace->visible($site)->find($thread->listing_id) : null;
            if ($item) {
                $photo = $item->media->first();

                return ['url' => '/marketplace/'.$item->id, 'title' => $item->title, 'thumbnail' => $photo ? '/marketplace/'.$item->id.'/thumbnail/'.$photo->id : null];
            }

            return null;
        }
        if ($thread->inquiry_job_id) {
            $jobs = app(JobBoard::class);
            $item = $jobs->enabled($site) ? $jobs->visible($site->location_id)->find($thread->inquiry_job_id) : null;

            return $item ? ['url' => '/jobs/'.$item->id, 'title' => $item->title, 'thumbnail' => null] : null;
        }
        if ($thread->inquiry_business_id) {
            $directory = app(BusinessDirectory::class);
            $item = $directory->enabled($site) ? $directory->visible($site)->find($thread->inquiry_business_id) : null;

            return $item ? ['url' => '/businesses/'.$item->slug, 'title' => $item->name, 'thumbnail' => null] : null;
        }

        return null;
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
            Conversation::whereKey($thread->id)->where($marker, '<', $seen)->update([$marker => $seen, 'updated_at' => DB::raw('updated_at')]);
        }
        $messages = $thread->messages()->with('sender:id,name')
            ->when(MessageAttachments::enabled(), fn ($query) => $query->withExists('attachment'))
            ->when(MessageDocuments::enabled(), fn ($query) => $query->withExists('document'))
            ->where('id', '>', $after)->orderBy('id')->limit(30)->get();

        return response()->json([
            'messages' => $messages->map(fn ($message) => [
                'id' => $message->id,
                'own' => $message->sender_id === $id,
                'sender' => $message->sender_id === $id ? 'You' : $message->sender->name,
                'body' => $message->body,
                'has_attachment' => (bool) $message->attachment_exists,
                'has_document' => (bool) $message->document_exists,
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
        $data = $request->validate(['body' => 'required|string|max:2000', 'photo' => $enabled ? 'nullable|file|mimes:jpg,jpeg,png,webp|max:4096' : 'prohibited',
            'document' => MessageDocuments::enabled() ? 'nullable|file|max:60|prohibits:photo' : 'prohibited']);
        abort_if($this->isBlocked($id, $thread->other($id)) || ! MessagingExtensions::canReply($thread), 403);
        $document = $request->hasFile('document') ? MessageDocuments::inspect($request->file('document')) : null;
        $media = [];
        try {
            if ($request->hasFile('photo')) {
                if (MessageAttachments::quotaExceeded($id, $conversation)) {
                    throw ValidationException::withMessages(['photo' => 'Photo attachment limit reached.']);
                }
                try {
                    $media = $photos->store([$request->file('photo')], 'attachments', MessageAttachments::DISK);
                } catch (ValidationException $exception) {
                    throw ValidationException::withMessages(['photo' => $exception->validator->errors()->first()]);
                }
            }
            DB::transaction(function () use ($id, $conversation, $data, $media, $document) {
                $thread = Conversation::forMember($id)->findOrFail($conversation);
                if ($thread->inquiry_business_id) {
                    Business::lockForUpdate()->findOrFail($thread->inquiry_business_id);
                }
                $this->lockParticipants($id, $thread->other($id));
                abort_if($this->isBlocked($id, $thread->other($id)) || ! MessagingExtensions::canReply($thread), 403, 'Messaging is blocked for this conversation.');
                if ($media && (! MessageAttachments::enabled() || MessageAttachments::quotaExceeded($id, $conversation, lock: true))) {
                    throw ValidationException::withMessages(['photo' => 'Photo attachments are unavailable or the limit has been reached.']);
                }
                if ($document && (! MessageDocuments::enabled() || MessageDocuments::quotaExceeded($id, $conversation))) {
                    throw ValidationException::withMessages(['document' => 'Text attachments are unavailable or the limit has been reached.']);
                }
                $message = $this->write($thread, $id, $data['body']);
                if ($document) {
                    $message->document()->create($document);
                }
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

    public function document(Request $request, int $conversation, int $message)
    {
        abort_unless(MessageDocuments::enabled(), 404);
        $thread = Conversation::forMember($request->user()->id)->findOrFail($conversation);
        $document = $thread->messages()->findOrFail($message)->document()->firstOrFail();
        abort_unless(strlen($document->content) === (int) $document->bytes && hash_equals($document->sha256, hash('sha256', $document->content)), 404);

        return response($document->content, 200, [
            'Content-Type' => 'text/plain; charset=utf-8', 'Content-Disposition' => 'attachment; filename="canadamb-document.txt"',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'X-Robots-Tag' => 'noindex, nofollow',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    public function blockedMembers(Request $request)
    {
        $filters = $request->validate(['q' => 'nullable|string|max:100']);
        $query = User::whereIn('id', DB::table('user_blocks')->where('user_id', $request->user()->id)->select('blocked_id'));
        $total = (clone $query)->count();
        $members = $query->when(filled($filters['q'] ?? null), fn ($query) => KeywordSearch::apply($query, $filters['q'], ['name']))
            ->orderBy('name')->orderBy('id')->paginate(20, ['id', 'name'])->withQueryString();

        return view('messages.blocked', compact('members', 'filters', 'total'));
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
