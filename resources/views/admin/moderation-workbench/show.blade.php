<x-layouts.admin title="Review submission">
    <div class="inline-actions">
        <a class="text-link" href="{{ route('moderation.index', ['type' => $type, 'status' => 'all']) }}">Back to moderation</a>
        @unless($type === 'claim')
            <a class="text-link" href="{{ route('moderation.reports.index', ['type' => $type, 'source' => $row->id, 'status' => 'all']) }}">Reports for this submission</a>
        @endunless
    </div>
    <div class="page-intro"><div>
        <p class="field-note">{{ $label }} · {{ ucfirst($resource->status) }} @if($row->reported) · Reported @endif</p>
        <h1>{{ $title }}</h1>
    </div></div>
    <section class="panel form-stack" aria-label="Submitted content">
        <dl class="moderation-metadata">
            <div><dt>Submitted by</dt><dd>{{ $row->submitter ?? ($ownerNames->isNotEmpty() ? $ownerNames->join(', ') : 'Account unavailable') }}</dd></div>
            <div><dt>Location</dt><dd>{{ $row->location ?? ($branches->isEmpty() ? 'Manitoba' : $branches->map(fn ($branch) => $branch->name ?? $branch->location->name)->unique()->join(', ')) }}</dd></div>
            @foreach(['created_at' => 'Submitted', 'updated_at' => 'Last edited'] as $key => $name)
                @if($resource->{$key})
                    @php
                        $time = \Illuminate\Support\Carbon::parse($resource->{$key})->timezone('America/Winnipeg');
                    @endphp
                    <div><dt>{{ $name }}</dt><dd><time datetime="{{ $time->toAtomString() }}">{{ $time->format('M j, Y · g:i a T') }}</time></dd></div>
                @endif
            @endforeach
        </dl>
        @if($type === 'claim')
            <h2>Private ownership evidence</h2>
            <p>Account email · {{ $claimAccount }}</p>
            <p>Business contact · {{ $resource->contact_email }}</p>
            <p class="prose-text">{{ $resource->evidence }}</p>
            <p>Confirm ownership independently before approving.</p>
        @else
            <p class="prose-text">{{ ($type === 'event' ? $resource->thread?->body : null) ?? $resource->description ?? $resource->body ?? '' }}</p>
        @endif
        @foreach($branches as $branch)<p>{{ $branch->address }}</p>@endforeach
        @if($type === 'listing')
            <p>{{ $resource->categoryRecord->name }} · {{ $resource->priceLabel() }} · {{ ucfirst($resource->condition) }}</p>
            @include('admin.moderation-workbench.listing-photos', ['item' => $resource])
        @elseif($type === 'job')
            <p>{{ $resource->branch->business->name }} · Closes {{ $resource->closing_on->format('M j, Y') }}</p>
            <p>{{ $resource->payLabel() }}</p>
            <a class="text-link" href="{{ $resource->apply_url }}" rel="noreferrer noopener">View application link</a>
        @elseif($type === 'event')
            @if($resource->thread)
                <p>Source discussion · {{ ucfirst($resource->thread->status) }} @if($resource->thread->locked) · Locked @endif</p>
                <a class="text-link" href="{{ route('moderation.items.show', ['type' => 'thread', 'item' => $resource->thread_id]) }}">Review source discussion</a>
            @else
                <p>Legacy standalone event</p>
            @endif
            <p>{{ $resource->venue }} · {{ $resource->organizer }} · {{ $resource->localStart()->format('M j, Y · g:i a T') }}</p>
            @if($resource->localEnd())<p>Ends {{ $resource->localEnd()->format('M j, Y · g:i a T') }}</p>@endif
            @if($resource->event_url)<a class="text-link" rel="noreferrer noopener" href="{{ $resource->event_url }}">View event link</a>@endif
        @elseif($type === 'reply')
            <a class="text-link" href="{{ route('moderation.items.show', ['type' => 'thread', 'item' => $resource->thread_id]) }}">Review parent discussion</a>
        @elseif($type === 'business-update')
            <a class="text-link" href="{{ route('moderation.items.show', ['type' => 'business', 'item' => $resource->branch->business_id]) }}">Review business profile</a>
        @endif
    </section>
    @php
        $actions = match ($resource->status) {
            'pending' => ['approve' => 'Approve', 'reject' => 'Reject'],
            'active' => $type === 'listing' ? ['remove' => 'Remove from public view'] : ['reject' => 'Remove from public view'],
            'approved' => match ($type) {
                'claim' => [],
                'thread' => [
                    ($resource->pinned ? 'unpin' : 'pin') => $resource->pinned ? 'Unpin' : 'Pin',
                    ($resource->locked ? 'unlock' : 'lock') => $resource->locked ? 'Unlock' : 'Lock',
                    'reject' => 'Remove from public view',
                ],
                default => ['reject' => 'Remove from public view'],
            },
            'cancelled' => $type === 'event' ? ['reject' => 'Remove from public view'] : [],
            default => [],
        };
        $notesRequired = in_array($type, ['business', 'claim', 'business-update'], true);
        $threadVersion = $type === 'event' && $resource->thread ? \App\Support\ModerationDecision::version('thread', $resource->thread) : null;
        if ($type === 'event' && in_array($resource->status, ['pending', 'approved'], true)) {
            $actions['cancel'] = 'Cancel event';
            if ($resource->thread && !\App\Support\LinkedEvents::matches($resource, $resource->thread)) {
                unset($actions['approve']);
            }
        }
    @endphp
    @if($actions)
        <section class="panel form-stack" aria-labelledby="moderation-decision">
            <h2 id="moderation-decision">Review decision</h2>
            <form method="post" action="{{ route('moderation.items.decide', ['type' => $type, 'item' => $row->id]) }}" class="form-stack">
                @csrf<input type="hidden" name="version" value="{{ $version }}">
                @if($threadVersion)<input type="hidden" name="thread_version" value="{{ $threadVersion }}">@endif
                @if(isset($actions['reject']) || isset($actions['remove']))
                    <label>Reason shown to the submitter when rejected or removed
                        <textarea name="reason" maxlength="{{ $type === 'listing' ? 240 : 300 }}" rows="2">{{ old('reason') }}</textarea>
                    </label>
                @endif
                <label>Internal reviewer notes @if($notesRequired) (required) @endif
                    <textarea name="notes" maxlength="1200" rows="2" @if($notesRequired) required minlength="8" @endif>{{ old('notes') }}</textarea>
                </label>
                <div class="inline-actions">
                    @foreach($actions as $action => $label)
                        <button class="button {{ $action === 'approve' ? 'button-primary' : 'button-outline' }}" name="action" value="{{ $action }}">{{ $label }}</button>
                    @endforeach
                </div>
            </form>
        </section>
    @endif
    @if($type === 'event' && in_array($resource->status, ['pending', 'approved'], true)
        && (!$resource->thread || \App\Support\LinkedEvents::matches($resource, $resource->thread)))
        <details class="panel">
            <summary>Edit event metadata</summary>
            <form method="post" action="{{ route('moderation.items.decide', ['type' => 'event', 'item' => $resource->id]) }}" class="form-stack">
                @csrf
                <input type="hidden" name="version" value="{{ $version }}">
                @if($threadVersion)<input type="hidden" name="thread_version" value="{{ $threadVersion }}">@endif
                <x-event-metadata :item="$resource"/>
                <label>Reason for changing the event<input name="reason" required maxlength="300"></label>
                <label>Internal reviewer notes<textarea name="notes" maxlength="1200"></textarea></label>
                <div><button class="button button-outline" name="action" value="edit">Save for review</button></div>
            </form>
        </details>
    @endif
    <section class="panel form-stack" aria-labelledby="moderation-history">
        <h2 id="moderation-history">Decision history</h2>
        @forelse($history as $decision)
            @php
                $context = json_decode($decision->context, true) ?? [];
                $time = \Illuminate\Support\Carbon::parse($decision->created_at)->timezone('America/Winnipeg');
            @endphp
            <article class="form-stack moderation-history">
                <h3>{{ ucfirst(str_replace('-', ' ', \Illuminate\Support\Str::afterLast($decision->action, '.'))) }}</h3>
                <p class="field-note">{{ $decision->name ?? (isset($context['approval_policy_revision']) ? 'Approval policy' : 'Reviewer unavailable') }} ·
                    <time datetime="{{ $time->toAtomString() }}">{{ $time->format('M j, Y · g:i a T') }}</time>
                    @if(isset($context['approval_policy_revision'])) · Revision {{ $context['approval_policy_revision'] }} @endif</p>
                @if(isset($context['previous_status'], $context['status']))
                    <p class="field-note">{{ ucfirst($context['previous_status']) }} → {{ ucfirst($context['status']) }}</p>
                @endif
                @if($decision->reason ?? $context['reason'] ?? null)<p class="prose-text">Reason · {{ $decision->reason ?? $context['reason'] }}</p>@endif
                @if($decision->notes ?? $context['notes'] ?? null)<p class="prose-text">Internal notes · {{ $decision->notes ?? $context['notes'] }}</p>@endif
            </article>
        @empty<p>No moderation decisions recorded for this submission.</p>@endforelse
        {{ $history->links() }}
    </section>
</x-layouts.admin>
