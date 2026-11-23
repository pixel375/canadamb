<?php

namespace App\Http\Controllers;

use App\Models\CommunityEvent;
use App\Models\CommunityThread;
use App\Models\Location;
use App\Support\ApprovalPolicy;
use App\Support\Community;
use App\Support\EventMetadata;
use App\Support\LinkedEvents;
use App\Support\LocationScope;
use App\Support\ModerationDecision;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DiscussionEventController extends Controller
{
    public function __construct(private readonly Community $community) {}

    public function create(Request $request, int $thread)
    {
        $source = $this->source($request)->findOrFail($thread);
        abort_if(CommunityEvent::where('thread_id', $thread)->exists(), 409, 'Use My Events to manage the existing request.');

        return $this->form($source);
    }

    public function store(Request $request, int $thread, ApprovalPolicy $policy)
    {
        $site = $request->attributes->get('site');
        $status = DB::transaction(function () use ($request, $thread, $site, $policy) {
            $source = $this->source($request)->lockForUpdate()->findOrFail($thread);
            $data = $request->validate(['thread_version' => ModerationDecision::VERSION_RULES, 'location_id' => 'nullable|integer']);
            ModerationDecision::requireCurrent('thread', $source, $data['thread_version']);
            abort_if(CommunityEvent::where('thread_id', $thread)->lockForUpdate()->first(['id']) !== null, 409,
                'Use My Events to manage the existing request.');
            $location = $this->location($request, $source, $data);
            $event = new CommunityEvent(EventMetadata::validated($request, $this->community) + $this->sourceDetails($source, $location));
            $revision = $policy->automaticRevision($site, 'event', $location);
            $event->forceFill(['thread_id' => $source->id, 'status' => $revision === null ? 'pending' : 'approved'])->save();
            LinkedEvents::record($event, $revision === null ? 'request' : 'auto-approve', $revision === null ? $source->author_id : null,
                null, ['approval_policy_revision' => $revision, 'site_id' => $site->id]);

            return $event->status;
        }, attempts: 3);

        return redirect('/account/events')->with('status', $status === 'approved' ? 'Event published.' : 'Event submitted for review.');
    }

    public function owned(Request $request)
    {
        $items = $this->ownedEvents($request)->with('thread')->latest('starts_at')->latest('id')->paginate(20);
        $rejected = $items->getCollection()->where('status', 'rejected')->modelKeys();
        $reasons = $rejected === [] ? collect() : DB::table('moderation_decisions')->where('type', 'event')->where('action', 'reject')
            ->whereIn('resource_id', $rejected)->whereIn('id', function ($latest) use ($rejected) {
                $latest->selectRaw('MAX(id)')->from('moderation_decisions')->where('type', 'event')->where('action', 'reject')
                    ->whereIn('resource_id', $rejected)->groupBy('resource_id');
            })->pluck('reason', 'resource_id');
        $site = $request->attributes->get('site');
        $editingEnabled = $this->community->enabled($site, 'events') && $this->community->enabled($site, 'community');

        return view('community.own-events', compact('items', 'reasons', 'editingEnabled'));
    }

    public function edit(Request $request, int $event)
    {
        $item = $this->ownedEvents($request)->whereNotNull('thread_id')->with('thread')->findOrFail($event);
        $source = $this->source($request)->findOrFail($item->thread_id);
        $item->setRelation('thread', $source);

        return $this->form($source, $item);
    }

    public function update(Request $request, int $event)
    {
        DB::transaction(function () use ($request, $event) {
            $item = $this->lockOwned($request, $event);
            $source = $item->thread;
            $site = $request->attributes->get('site');
            abort_unless($source && $this->community->enabled($site, 'community') && $this->community->enabled($site, 'events'), 404);
            $this->requireVersions($request, $item);
            abort_unless(LinkedEvents::matches($item, $source), 409, 'The source discussion is ineligible.');
            $location = $this->location($request, $source, $request->validate(['location_id' => 'nullable|integer']), $item->location_id);
            $item->fill(EventMetadata::validated($request, $this->community) + $this->sourceDetails($source, $location));
            $before = $item->status;
            if (in_array($before, ['approved', 'pending'], true) && ! $item->isDirty()) {
                return;
            }
            LinkedEvents::preserveReportedMetadata($item);
            $item->forceFill(['status' => 'pending', 'cancelled_at' => null, 'cancellation_note' => null])->save();
            LinkedEvents::record($item, in_array($before, ['approved', 'pending'], true) ? 'author-edit' : 'author-resubmit',
                $request->user()->id, $before);
        }, attempts: 3);

        return redirect('/account/events')->with('status', 'Event request saved.');
    }

    public function cancel(Request $request, int $event)
    {
        $data = $request->validate(['cancellation_note' => 'nullable|string|max:300']);
        DB::transaction(function () use ($request, $event, $data) {
            $item = $this->lockOwned($request, $event);
            if ($item->thread_id !== null) {
                $this->requireVersions($request, $item);
            }
            $before = $item->status;
            abort_unless(in_array($before, ['pending', 'approved'], true), 409);
            $item->forceFill(['status' => $before === 'approved' ? 'cancelled' : 'withdrawn', 'cancelled_at' => now(),
                'cancellation_note' => $data['cancellation_note'] ?? null])->save();
            LinkedEvents::record($item, 'author-cancel', $request->user()->id, $before);
        }, attempts: 3);

        return redirect('/account/events')->with('status', 'Event cancelled.');
    }

    private function source(Request $request): Builder
    {
        $site = $request->attributes->get('site');
        abort_unless($this->community->enabled($site, 'community') && $this->community->enabled($site, 'events'), 404);

        return CommunityThread::where('author_id', $request->user()->id)->where('status', 'approved')->where('locked', false)
            ->when($site->location_id !== null, fn (Builder $thread) => $thread->where('location_id', $site->location_id));
    }

    private function ownedEvents(Request $request): Builder
    {
        $location = $request->attributes->get('site')->location_id;

        return $this->community->events($location)->where('author_id', $request->user()->id)
            ->when($location !== null, fn (Builder $events) => $events->where(fn (Builder $local) => $local->whereNull('thread_id')
                ->orWhereHas('thread', fn (Builder $thread) => $thread->where('location_id', $location))));
    }

    private function lockOwned(Request $request, int $event): CommunityEvent
    {
        $this->ownedEvents($request)->whereKey($event)->firstOrFail(['id']);
        $item = LinkedEvents::lock($event);
        $location = $request->attributes->get('site')->location_id;
        abort_unless($item->author_id === $request->user()->id
            && ($location === null || ($item->location_id === $location && ($item->thread_id === null || $item->thread->location_id === $location))), 404);

        return $item;
    }

    private function requireVersions(Request $request, CommunityEvent $item): void
    {
        $versions = $request->validate(['version' => ModerationDecision::VERSION_RULES, 'thread_version' => ModerationDecision::VERSION_RULES]);
        ModerationDecision::requireCurrent('thread', $item->thread, $versions['thread_version']);
        ModerationDecision::requireCurrent('event', $item, $versions['version']);
    }

    private function location(Request $request, CommunityThread $thread, array $data, ?int $existing = null): int
    {
        $requested = isset($data['location_id']) ? (int) $data['location_id'] : null;
        $fixed = $thread->location_id ?? $existing;
        if ($fixed !== null && $requested !== null && $requested !== $fixed) {
            throw ValidationException::withMessages(['location_id' => 'This request keeps its original Manitoba location.']);
        }

        return LocationScope::forPublication($request->attributes->get('site'), $fixed ?? $requested);
    }

    private function sourceDetails(CommunityThread $source, int $location): array
    {
        return ['title' => $source->title, 'body' => $source->body, 'author_id' => $source->author_id, 'location_id' => $location];
    }

    private function form(CommunityThread $source, ?CommunityEvent $item = null)
    {
        $places = $source->location_id === null && $item === null ? Location::selectable()->orderBy('name')->get(['id', 'name']) : collect();

        return view('community.event-request', ['source' => $source, 'item' => $item, 'places' => $places,
            'threadVersion' => ModerationDecision::version('thread', $source),
            'version' => $item === null ? null : ModerationDecision::version('event', $item)]);
    }
}
