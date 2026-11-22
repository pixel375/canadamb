<?php

namespace App\Http\Controllers;

use App\Models\BusinessLocation;
use App\Models\Conversation;
use App\Models\JobPosting;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use App\Support\Audit;
use App\Support\BusinessDirectory;
use App\Support\ConversationAccess;
use App\Support\JobBoard;
use App\Support\Marketplace;
use App\Support\MessageAttachments;
use App\Support\MessageContact;
use App\Support\NotificationInbox;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\HeaderUtils;

class MessagesController extends Controller
{
    private const CONTEXT = ['listing.location', 'listing.categoryRecord', 'branch.business', 'job.branch.business', 'location', 'buyer:id,name', 'seller:id,name'];

    public function index(Request $request)
    {
        $member = $request->user()->id;
        $visible = $this->visible($request);
        $filters = array_filter($request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', Rule::in(array_keys(ConversationAccess::TYPES))],
        ]), fn ($value) => $value !== null);

        $unreadCriteria = fn ($query) => $query->where('messages.sender_id', '!=', $member)
            ->whereRaw(ConversationAccess::unreadSql('conversations', 'messages', '?', '?'), [$member, $member])
            ->whereRaw(ConversationAccess::unblockedSql('conversations'));
        $totalUnread = $unreadCriteria((clone $visible)->join('messages', 'messages.conversation_id', '=', 'conversations.id'))->count('messages.id');
        $threads = $visible->with([...self::CONTEXT, 'latestMessage' => fn ($message) => $message
            ->select('messages.id', 'messages.conversation_id', 'messages.sender_id')])
            ->withCount(['messages as unread' => $unreadCriteria]);
        if (isset($filters['type'])) {
            $threads->where('context_type', $filters['type']);
        }
        if (isset($filters['q'])) {
            $term = '%'.strtr($filters['q'], ['!' => '!!', '%' => '!%', '_' => '!_']).'%';
            $matches = static fn ($column) => fn (Builder $query) => $query->whereRaw("$column LIKE ? ESCAPE '!'", [$term]);
            $threads->where(fn (Builder $query) => $query->whereHas('listing', $matches('title'))
                ->orWhereHas('branch.business', $matches('name'))->orWhereHas('job', $matches('title'))
                ->orWhereHas('job.branch.business', $matches('name'))->orWhereHas('listing.location', $matches('name'))
                ->orWhereHas('location', $matches('name'))
                ->orWhere(fn (Builder $buyer) => $buyer->where('seller_id', $member)->whereHas('buyer', $matches('name')))
                ->orWhere(fn (Builder $seller) => $seller->where('buyer_id', $member)->whereHas('seller', $matches('name'))));
        }

        return view('messages.index', [
            'threads' => $threads->latest('updated_at')->latest('id')->paginate(20)->appends($filters),
            'member' => $member, 'totalUnread' => $totalUnread, 'filters' => $filters, 'types' => ConversationAccess::TYPES,
        ]);
    }

    public function contactBusiness(Request $request, string $slug, int $branch)
    {
        [$item, $recipient] = $this->businessContext($request, $slug, $branch);
        $this->assertCanContact($request->user()->id, $recipient);

        return view('messages.contact', [
            'subject' => $item->business->name, 'area' => $item->location->name, 'module' => 'Business',
            'action' => "/businesses/$slug/branches/$branch/message", 'back' => "/businesses/$slug",
        ]);
    }

    public function contactJob(Request $request, int $job)
    {
        [$item, $recipient] = $this->jobContext($request, $job);
        $this->assertCanContact($request->user()->id, $recipient);

        return view('messages.contact', [
            'subject' => $item->title, 'area' => $item->branch->location->name, 'module' => 'Job',
            'action' => "/jobs/$job/message", 'back' => "/jobs/$job",
        ]);
    }

    public function start(Request $request, int $listing, Marketplace $marketplace)
    {
        $site = $request->attributes->get('site');
        abort_unless($marketplace->enabled($site), 404);
        $scope = fn () => $marketplace->visible($site)->without(['location', 'categoryRecord', 'media']);
        $this->assertCanContact($request->user()->id, $scope()->findOrFail($listing)->user_id);

        return $this->send($request, function (int $member) use ($scope, $listing): Conversation {
            $item = $scope()->lockForUpdate()->findOrFail($listing);

            return $this->findOrStart('marketplace', ['listing_id' => $listing], $member, $item->user_id, $item->location_id);
        }, firstContact: true);
    }

    public function startBusiness(Request $request, string $slug, int $branch)
    {
        [, $recipient] = $this->businessContext($request, $slug, $branch);
        $this->assertCanContact($request->user()->id, $recipient);

        return $this->send($request, function (int $member) use ($request, $slug, $branch): Conversation {
            [$item, $recipient] = $this->businessContext($request, $slug, $branch, true);

            return $this->findOrStart('business', ['business_location_id' => $item->id], $member, $recipient, $item->location_id, $item->business_id);
        }, requireKey: true, firstContact: true);
    }

    public function startJob(Request $request, int $job)
    {
        [, $recipient] = $this->jobContext($request, $job);
        $this->assertCanContact($request->user()->id, $recipient);

        return $this->send($request, function (int $member) use ($request, $job): Conversation {
            [$item, $recipient] = $this->jobContext($request, $job, true);

            return $this->findOrStart('job', ['job_posting_id' => $item->id], $member, $recipient, $item->branch->location_id, $item->branch->business_id);
        }, requireKey: true, firstContact: true);
    }

    public function show(Request $request, int $conversation)
    {
        $member = $request->user()->id;
        $thread = $this->visible($request)->with(self::CONTEXT)->findOrFail($conversation);
        $blocked = ConversationAccess::blocked($member, $thread->other($member));
        $relations = $blocked ? ['sender:id,name'] : ['sender:id,name', 'attachments:id,message_id,mime_type,original_name,size_bytes'];
        $history = $thread->messages()->with($relations)->latest('id');
        $messages = $request->has('page') && ! $request->has('cursor') ? $history->paginate(30) : $history->cursorPaginate(30);
        $this->markDisplayedRead($request, $thread, $messages->getCollection());
        $mine = DB::table('user_blocks')->where(['user_id' => $member, 'blocked_id' => $thread->other($member)])->exists();

        return view('messages.show', [
            'thread' => $thread, 'messages' => $messages, 'blocked' => $blocked, 'myBlock' => $mine,
            'replyAllowed' => ! $blocked && $this->responderAvailable($thread), 'member' => $member,
        ]);
    }

    public function reply(Request $request, int $conversation)
    {
        $thread = $this->visible($request)->findOrFail($conversation);
        abort_unless($this->responderAvailable($thread), 403, 'Replies are closed because the representative no longer has access.');
        abort_if(ConversationAccess::blocked($thread->buyer_id, $thread->seller_id), 403, 'New messages are blocked.');

        return $this->send($request, function (int $member) use ($request, $thread): Conversation {
            $this->lockParticipants($thread->buyer_id, $thread->seller_id);
            $locked = $this->visible($request)->lockForUpdate()->findOrFail($thread->id);
            abort_unless($this->responderAvailable($locked, true), 403, 'Replies are closed because the representative no longer has access.');
            abort_if(ConversationAccess::blocked($member, $locked->other($member), true), 403, 'New messages are blocked.');

            return $locked;
        }, requireKey: $thread->context_type !== 'marketplace');
    }

    public function block(Request $request, int $conversation)
    {
        return $this->toggleBlock($request, $conversation, true);
    }

    public function unblock(Request $request, int $conversation)
    {
        return $this->toggleBlock($request, $conversation, false);
    }

    public function report(Request $request, int $conversation)
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:8', 'max:500'],
            'message_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $member = $request->user()->id;
        $created = $this->privateWrite($member, fn () => DB::transaction(function () use ($request, $conversation, $member, $data): bool {
            $thread = $this->visible($request)->lockForUpdate()->findOrFail($conversation);
            $message = isset($data['message_id']) ? $thread->messages()->where('sender_id', '!=', $member)->findOrFail($data['message_id']) : null;
            if (DB::table('conversation_reports')->where(['conversation_id' => $thread->id, 'reporter_id' => $member])->exists()) {
                return false;
            }
            $report = DB::table('conversation_reports')->insertGetId([
                'conversation_id' => $thread->id, 'reporter_id' => $member, 'message_id' => $message?->id,
                'reason' => $data['reason'], 'created_at' => now(),
            ]);
            Audit::record('message.reported', $member, context: ['conversation' => $thread->id, 'message' => $message?->id, 'report' => $report]);

            return true;
        }));

        return redirect("/account/messages/$conversation")->with('status', $created ? 'Conversation report submitted.' : 'You have already reported this conversation.');
    }

    public function attachment(Request $request, int $conversation, int $attachment)
    {
        $threads = $this->visible($request)->whereKey($conversation)
            ->whereRaw(ConversationAccess::unblockedSql('conversations'))->select('id');
        $file = MessageAttachment::whereIn('message_id', Message::whereIn('conversation_id', $threads)->select('id'))->findOrFail($attachment);
        abort_unless(MessageAttachments::acceptsPath($file->path, $file->mime_type), 404);
        $stream = MessageAttachments::disk()->readStream($file->path);
        abort_unless(is_resource($stream), 404);
        $image = $file->mime_type === 'image/webp';
        $name = preg_match('/\A[a-z0-9][a-z0-9._ -]{0,119}\z/i', $file->original_name)
            ? $file->original_name : ($image ? 'photo.webp' : 'document.pdf');

        return response()->stream(function () use ($stream): void {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => $file->mime_type,
            'Content-Disposition' => HeaderUtils::makeDisposition($image ? 'inline' : 'attachment', $name),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    private function visible(Request $request): Builder
    {
        return ConversationAccess::forMember($request->attributes->get('site'), $request->user()->id);
    }

    private function markDisplayedRead(Request $request, Conversation $thread, Collection $displayed): void
    {
        $member = $request->user()->id;
        $legacyMarker = $thread->{$thread->marker($member)};
        $ids = $displayed->filter(fn (Message $message) => $message->sender_id !== $member && $message->id > $legacyMarker)
            ->pluck('id')->all();
        if ($ids === []) {
            return;
        }
        DB::transaction(function () use ($request, $thread, $member, $ids): void {
            $this->visible($request)->lockForUpdate()->findOrFail($thread->id);
            $pending = DB::table('messages as displayed')->where('displayed.conversation_id', $thread->id)->whereIn('displayed.id', $ids)
                ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('message_reads as prior')
                    ->whereColumn('prior.message_id', 'displayed.id')->where('prior.user_id', $member))
                ->select('displayed.id')->selectRaw('?, ?', [$member, now()]);
            DB::table('message_reads')->insertUsing(['message_id', 'user_id', 'read_at'], $pending);
        }, attempts: 3);
    }

    /** @return array{BusinessLocation,int} */
    private function businessContext(Request $request, string $slug, int $branch, bool $lock = false): array
    {
        $site = $request->attributes->get('site');
        abort_unless(app(BusinessDirectory::class)->enabled($site), 404);
        $item = BusinessLocation::with(['business', 'location'])
            ->whereHas('business', fn ($query) => $query->where('slug', $slug)->where('status', 'active'))
            ->when($site->location_id !== null, fn ($query) => $query->where('location_id', $site->location_id))
            ->when($lock, fn ($query) => $query->lockForUpdate())->findOrFail($branch);
        $recipient = app(MessageContact::class)->forBranch($item);
        abort_unless($recipient !== null, 404);

        return [$item, $recipient];
    }

    /** @return array{JobPosting,int} */
    private function jobContext(Request $request, int $job, bool $lock = false): array
    {
        $site = $request->attributes->get('site');
        $board = app(JobBoard::class);
        abort_unless($board->enabled($site), 404);
        $item = $board->visible($site->location_id)->when($lock, fn ($query) => $query->lockForUpdate())->findOrFail($job);
        $recipient = app(MessageContact::class)->forJob($item);
        abort_unless($recipient !== null, 404);

        return [$item, $recipient];
    }

    private function findOrStart(string $type, array $context, int $member, int $recipient, int $location, ?int $business = null): Conversation
    {
        $this->lockParticipants($member, $recipient);
        abort_if($business !== null && ! $this->lockRepresentative($business, $recipient), 404);
        $this->assertCanContact($member, $recipient, true);
        $key = $context + ['buyer_id' => $member, 'context_type' => $type];
        if ($type !== 'marketplace') {
            $key['seller_id'] = $recipient;
        }
        $thread = Conversation::where($key)->lockForUpdate()->first();
        if ($thread) {
            abort_unless($thread->seller_id === $recipient, 409);

            return $thread;
        }
        $recent = Conversation::where('buyer_id', $member)->where('created_at', '>=', now()->subHour())
            ->lockForUpdate()->limit(12)->get(['id'])->count();
        abort_if($recent >= 12, 429, 'Too many new contacts. Try again later.');

        return Conversation::create($key + ['seller_id' => $recipient, 'location_id' => $type === 'marketplace' ? null : $location]);
    }

    private function assertCanContact(int $member, int $recipient, bool $lock = false): void
    {
        abort_if($member === $recipient, 403, 'You cannot message yourself.');
        abort_if(ConversationAccess::blocked($member, $recipient, $lock), 403, 'New messages are blocked.');
    }

    private function responderAvailable(Conversation $thread, bool $lock = false): bool
    {
        if ($thread->context_type === 'marketplace') {
            return true;
        }
        if ($lock) {
            $business = $thread->context_type === 'business'
                ? BusinessLocation::whereKey($thread->business_location_id)->value('business_id')
                : BusinessLocation::whereKey(JobPosting::whereKey($thread->job_posting_id)->value('business_location_id'))->value('business_id');

            return $business !== null && $this->lockRepresentative($business, $thread->seller_id);
        }

        return Conversation::whereKey($thread->id)->whereRaw(ConversationAccess::responderSql('conversations'))
            ->whereHas('seller', fn (Builder $seller) => $seller->whereNotNull('email_verified_at'))->exists();
    }

    private function lockRepresentative(int $business, int $recipient): bool
    {
        return app(MessageContact::class)->eligibleMembers($business)->where('users.id', $recipient)
            ->lockForUpdate()->first(['users.id']) !== null;
    }

    private function send(Request $request, Closure $resolve, bool $requireKey = false, bool $firstContact = false)
    {
        $member = $request->user()->id;
        $data = $request->validate([
            'body' => ['nullable', 'required_without:attachments', 'string', 'max:2000'],
            'attachments' => ['sometimes', 'array', 'list', 'max:3'],
            'attachments.*' => ['required', 'file', 'max:5120'],
            'idempotency_key' => ['bail', Rule::requiredIf($requireKey || $request->hasFile('attachments')), 'uuid'],
        ]);
        $key = isset($data['idempotency_key']) ? strtolower($data['idempotency_key']) : null;
        $thread = $this->privateWrite($member, fn () => app(MessageAttachments::class)->withStored($request->file('attachments', []),
            fn (array $files) => DB::transaction(function () use ($resolve, $member, $data, $key, $files, $firstContact): Conversation {
                $thread = $resolve($member);
                $this->write($thread, $member, $data['body'] ?? '', $key, $files, $firstContact);

                return $thread;
            }, attempts: 3)));

        return redirect('/account/messages/'.$thread->id);
    }

    private function privateWrite(int $member, Closure $write): mixed
    {
        try {
            return $write();
        } catch (QueryException $error) {
            // Driver exceptions contain SQL bindings that can contain private text or filenames.
            Log::warning('Messaging write failed.', ['actor' => $member, 'sqlstate' => $error->errorInfo[0] ?? null]);
            abort(503, 'Messaging is temporarily unavailable.');
        }
    }

    private function write(Conversation $thread, int $member, string $body, ?string $key, array $files, bool $firstContact): void
    {
        if ($key !== null) {
            $previous = Message::where(['sender_id' => $member, 'idempotency_key' => $key])->lockForUpdate()->first(['conversation_id'])?->conversation_id;
            if ($previous === null) {
                $replay = DB::table('message_send_replays')->where(['sender_id' => $member, 'idempotency_key' => $key])
                    ->lockForUpdate()->first(['message_id']);
                if ($replay) {
                    $previous = Message::whereKey($replay->message_id)->lockForUpdate()->first(['conversation_id'])?->conversation_id;
                }
            }
            abort_if($previous !== null && (int) $previous !== $thread->id, 409);
            if ($previous !== null) {
                return;
            }
        }
        if ($files === [] && ($key === null || $firstContact)) {
            // Coalesce repeated initial outreach and immediate replays from older Marketplace forms.
            $previous = $thread->messages()->where('sender_id', $member)->where('created_at', '>=', now()->subSeconds(30))
                ->latest('id')->lockForUpdate()->first(['id', 'body']);
            if ($previous?->body === $body) {
                if ($key !== null) {
                    DB::table('message_send_replays')->insert(['sender_id' => $member, 'idempotency_key' => $key, 'message_id' => $previous->id]);
                }

                return;
            }
        }
        $message = $thread->messages()->create(['sender_id' => $member, 'body' => $body, 'idempotency_key' => $key]);
        if ($files !== []) {
            DB::table('message_attachments')->insert(array_map(fn (array $file) => $file + ['message_id' => $message->id], $files));
        }
        Conversation::whereKey($thread->id)->update(['updated_at' => now()]);
        $location = $thread->context_type === 'marketplace' ? $thread->listing()->value('location_id') : $thread->location_id;
        NotificationInbox::record($thread->other($member), 'message.received', $message->id, $message->id, [$location], $thread->context_type);
    }

    private function toggleBlock(Request $request, int $conversation, bool $block)
    {
        $member = $request->user()->id;
        $item = $this->visible($request)->findOrFail($conversation);
        DB::transaction(function () use ($request, $item, $member, $block): void {
            $this->lockParticipants($item->buyer_id, $item->seller_id);
            $thread = $this->visible($request)->lockForUpdate()->findOrFail($item->id);
            $data = ['user_id' => $member, 'blocked_id' => $thread->other($member)];
            if ($block) {
                DB::table('user_blocks')->insertOrIgnore($data);
            } else {
                DB::table('user_blocks')->where($data)->delete();
            }
        }, attempts: 3);

        return redirect('/account/messages/'.$conversation);
    }

    private function lockParticipants(int $first, int $second): void
    {
        User::whereIn('id', [$first, $second])->orderBy('id')->lockForUpdate()->get(['id']);
    }
}
