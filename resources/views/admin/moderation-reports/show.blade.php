<x-layouts.admin title="Report review">
    <div class="page-intro">
        <div><a class="text-link" href="{{ route('moderation.reports.index') }}">Abuse reports</a><h1>{{ $discussionEvidence['title'] ?? $eventEvidence['title'] ?? $record->title ?? 'Source unavailable' }}</h1></div>
    </div>
    <article class="panel form-stack">
        <div>
            <p class="section-label">{{ \App\Support\ModerationReports::TYPES[$record->type] }} · {{ \App\Support\ModerationReports::STATUSES[$record->status] }}</p>
            <p>Report #{{ $record->id }} · {{ $location ?? ($record->type === 'business' ? 'Province-wide profile' : 'Location unspecified') }} · {{ $record->submitted_at ?? 'Submission date unavailable' }}</p>
        </div>
        @if($record->type === 'conversation')
            <form method="post" action="{{ route('moderation.reports.evidence', $record->id) }}" class="form-stack">
                @csrf
                <p>Access to the report submission and any specifically reported message is recorded in the audit log.</p>
                <div><button class="button button-outline">View reported evidence</button></div>
            </form>
        @else
            <div><h2>Report submission</h2><p class="prose-text">{{ $reason }}</p></div>
            @if($discussionEvidence)
                <div><h2>Reported discussion text</h2><p class="prose-text">{{ $discussionEvidence['body'] }}</p></div>
            @endif
            @if($eventEvidence)
                <div class="form-stack">
                    <h2>Reported event details</h2>
                    <p>{{ $eventEvidence['venue'] }} · {{ $eventEvidence['organizer'] }}</p>
                    <p>{{ \Illuminate\Support\Carbon::parse($eventEvidence['starts_at'], 'UTC')->setTimezone('America/Winnipeg')->format('M j, Y · g:i a T') }}</p>
                    <p class="prose-text">{{ $eventEvidence['body'] }}</p>
                </div>
            @endif
            @if($record->title !== null)
                <div><a class="text-link" href="{{ route('moderation.items.show', ['type' => $record->type, 'item' => $record->source_id]) }}">{{ $record->type === 'thread' ? 'Review current discussion' : 'Review reported content' }}</a></div>
            @endif
        @endif
    </article>
    @if($decision)
        <section class="panel form-stack">
            <h2>Report decision</h2>
            <p>{{ \App\Support\ModerationReports::STATUSES[$record->status] }} · {{ $decision->reviewer_name ?? 'Reviewer unavailable' }} · {{ $decision->created_at }}</p>
            @if($decision->note)<p class="prose-text">{{ $decision->note }}</p>@endif
        </section>
    @elseif($record->status === 'open')
        <form method="post" action="{{ route('moderation.reports.decision', ['type' => $record->type, 'report' => $record->id]) }}" class="panel form-stack">
            @csrf
            <input type="hidden" name="version" value="{{ $version }}">
            <h2>Report decision</h2>
            <label>Internal decision note<textarea name="note" required maxlength="240" rows="3">{{ old('note') }}</textarea></label>
            <div class="inline-actions">
                <button class="button button-primary" name="decision" value="resolved">Resolve report</button>
                <button class="button button-outline" name="decision" value="dismissed">Dismiss report</button>
            </div>
        </form>
    @endif
</x-layouts.admin>
