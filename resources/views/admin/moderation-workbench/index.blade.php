<x-layouts.admin title="Moderation">
    <div class="page-intro"><div><h1>Moderation</h1><p>Review submissions and investigate reports across Manitoba.</p></div></div>
    <div class="inline-actions">
        <a class="button button-outline" href="{{ route('moderation.reports.index') }}">Reports · {{ $openReports }} open</a>
        <a class="text-link" href="/admin/moderation/marketplace">Marketplace review with photo previews</a>
        <a class="button button-outline" href="/admin/ownership-cases">Private ownership transfers and disputes</a>
    </div>
    <form method="get" action="{{ route('moderation.index') }}" class="panel form-stack">
        <div class="moderation-filters">
            <label>Content type<select name="type">
                <option value="">All types</option>
                @foreach($types as $key => $label)<option value="{{ $key }}" @selected(($filters['type'] ?? null) === $key)>{{ $label }}</option>@endforeach
            </select></label>
            <label>Queue<select name="status">
                @foreach(['pending' => 'Pending', 'reported' => 'Reported', 'reviewed' => 'Reviewed', 'all' => 'All statuses'] as $key => $label)
                    <option value="{{ $key }}" @selected($filters['status'] === $key)>{{ $label }}</option>
                @endforeach
            </select></label>
            <label>Priority<select name="priority">
                @foreach(['oldest' => 'Oldest first', 'newest' => 'Newest first', 'reported' => 'Reported first'] as $key => $label)
                    <option value="{{ $key }}" @selected($filters['priority'] === $key)>{{ $label }}</option>
                @endforeach
            </select></label>
            <label>Location<select name="location">
                <option value="">All Manitoba</option>
                @foreach($locations as $place)<option value="{{ $place->id }}" @selected((int) ($filters['location'] ?? 0) === $place->id)>{{ $place->name }}</option>@endforeach
            </select></label>
            <label>Title or name<input type="search" name="q" maxlength="100" value="{{ $filters['q'] ?? '' }}"></label>
            <label>Submitted from<input type="date" name="from" value="{{ $filters['from'] ?? '' }}"></label>
            <label>Submitted through<input type="date" name="to" value="{{ $filters['to'] ?? '' }}"></label>
        </div>
        <div class="inline-actions"><button class="button button-primary">Apply filters</button><a class="text-link" href="{{ route('moderation.index') }}">Clear filters</a></div>
    </form>
    <section class="panel form-stack" aria-labelledby="moderation-counts">
        <h2 id="moderation-counts">Queue counts</h2>
        <div class="moderation-count-grid">
            @foreach($types as $key => $label)
                @php
                    $count = $counts[$key] ?? null;
                @endphp
                <div>
                    <a class="text-link" href="{{ route('moderation.index', array_replace($filters, ['type' => $key, 'status' => 'pending'])) }}">{{ $label }}</a>
                    <p class="field-note">{{ $count->pending ?? 0 }} pending · {{ $count->reported ?? 0 }} reported · {{ $count->reviewed ?? 0 }} reviewed</p>
                </div>
            @endforeach
        </div>
    </section>
    @php
        $batch = $items->isNotEmpty() && isset($filters['type']) && $filters['type'] !== 'claim' && $filters['status'] === 'pending';
    @endphp
    @if($batch)
        <form id="moderation-batch" action="{{ route('moderation.bulk.preview') }}" method="post" class="inline-actions">
            @csrf<input type="hidden" name="type" value="{{ $filters['type'] }}">
            <button class="button button-outline">Preview selected submissions</button>
        </form>
    @endif
    @forelse($items as $row)
        @php
            $listing = $row->type === 'listing' ? $listings->get($row->id) : null;
        @endphp
        <article class="panel moderation-row" @if($listing) data-review-version="{{ \App\Support\ListingPhotoOrder::version($listing) }}" @endif>
            <div>
                <p class="field-note">{{ $types[$row->type] }} · {{ ucfirst($row->source_status) }} @if($row->reported) · <strong>Reported</strong> @endif</p>
                <h2><a class="text-link" href="{{ route('moderation.items.show', ['type' => $row->type, 'item' => $row->id]) }}">{{ $row->title }}</a></h2>
                <p class="field-note">{{ $row->location ?? 'Manitoba' }} @if($row->submitter) · {{ $row->submitter }} @endif
                    @if($row->created_at) · {{ \Illuminate\Support\Carbon::parse($row->created_at)->timezone('America/Winnipeg')->format('M j, Y · g:i a T') }} @endif</p>
            </div>
            @if($listing && $listing->media->isNotEmpty())
                <img class="moderation-thumbnail" src="/marketplace/{{ $listing->id }}/thumbnail/{{ $listing->media->first()->id }}" alt="Submitted listing photo" loading="lazy">
            @elseif($batch)
                <label class="moderation-select"><input type="checkbox" name="ids[]" value="{{ $row->id }}" form="moderation-batch"> Select <span class="sr-only">{{ $row->title }}</span></label>
            @endif
        </article>
    @empty
        <div class="panel"><p>No submissions match these filters.</p></div>
    @endforelse
    {{ $items->links() }}
</x-layouts.admin>
