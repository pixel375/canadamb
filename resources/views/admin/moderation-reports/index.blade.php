@php
    $types = \App\Support\ModerationReports::TYPES;
    $statuses = \App\Support\ModerationReports::STATUSES;
    $bulkAvailable = filled($filters['type'] ?? null) && $filters['status'] === 'open' && $queue->isNotEmpty();
@endphp
<x-layouts.admin title="Abuse reports">
    <div class="page-intro">
        <div><a class="text-link" href="/admin/moderation">Moderation workbench</a><h1>Abuse reports</h1></div>
    </div>
    <section class="panel form-stack" aria-label="Report filters">
        <p>
            @foreach($statuses as $key => $status)
                @if(!$loop->first) · @endif{{ $status }} {{ $summary->where('status', $key)->sum('total') }}
            @endforeach
        </p>
        <form method="get" action="{{ route('moderation.reports.index') }}" class="form-stack">
            @if(filled($filters['source'] ?? null))
                <input type="hidden" name="source" value="{{ $filters['source'] }}">
                <p>Reports for content #{{ $filters['source'] }}</p>
            @endif
            <div class="form-grid">
                <label>Report type
                    <select name="type">
                        <option value="">All types</option>
                        @foreach($types as $key => $label)
                            <option value="{{ $key }}" @selected(($filters['type'] ?? '') === $key)>{{ $label }} ({{ $summary->where('type', $key)->where('status', 'open')->sum('total') }} open)</option>
                        @endforeach
                    </select>
                </label>
                <label>Status
                    <select name="status">
                        @foreach($statuses + ['all' => 'All statuses'] as $key => $label)
                            <option value="{{ $key }}" @selected($filters['status'] === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label>Manitoba location
                    <select name="location">
                        <option value="">All locations</option>
                        @foreach($locations as $key => $label)
                            <option value="{{ $key }}" @selected((string) ($filters['location'] ?? '') === (string) $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label>Priority
                    <select name="priority">
                        <option value="oldest" @selected($filters['priority'] === 'oldest')>Oldest first</option>
                        <option value="newest" @selected($filters['priority'] === 'newest')>Newest first</option>
                    </select>
                </label>
                <label>Content title<input name="q" maxlength="100" value="{{ $filters['q'] ?? '' }}"></label>
                <label>Submitted from<input type="date" name="from" value="{{ $filters['from'] ?? '' }}"></label>
                <label>Submitted through<input type="date" name="until" value="{{ $filters['until'] ?? '' }}"></label>
            </div>
            <div class="inline-actions">
                <button class="button button-primary">Apply filters</button>
                <a class="text-link" href="{{ route('moderation.reports.index') }}">Reset filters</a>
            </div>
        </form>
    </section>
    <section class="panel form-stack" aria-label="Reports queue">
        @if($bulkAvailable)
            <form id="report-batch" method="post" action="{{ route('moderation.reports.bulk.preview') }}">
                @csrf
                <input type="hidden" name="type" value="{{ $filters['type'] }}">
            </form>
        @endif
        @forelse($queue as $record)
            <article class="item-row">
                <div>
                    <p class="section-label">{{ $types[$record->type] }} · {{ $statuses[$record->status] }}</p>
                    <h2><a class="text-link" href="{{ route('moderation.reports.show', ['type' => $record->type, 'report' => $record->id]) }}">{{ $record->title ?? 'Source unavailable' }}</a></h2>
                    <p>Report #{{ $record->id }} · {{ $locations[$record->location_id] ?? ($record->type === 'business' ? 'Province-wide profile' : 'Location unspecified') }} · {{ $record->submitted_at ?? 'Submission date unavailable' }}</p>
                </div>
                @if($bulkAvailable)
                    <label class="checkbox"><input type="checkbox" value="{{ $record->id }}" name="items[]" form="report-batch"> Select report #{{ $record->id }}</label>
                @endif
            </article>
        @empty
            <p>No reports match these filters.</p>
        @endforelse
        @if($bulkAvailable)
            <div><button class="button button-outline" form="report-batch">Preview selected reports</button></div>
        @endif
    </section>
    {{ $queue->links() }}
</x-layouts.admin>
