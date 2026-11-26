<x-layouts.admin title="Feed health history">
    <div class="page-intro"><div><a class="text-link" href="/admin/news">Editorial queue</a><h1>Feed health history</h1><p>Read-only source status and recorded polling results. Opening this page does not fetch external feeds.</p></div></div>
    <p class="notice">{{ $polling ? 'Scheduled polling is configured; scheduler execution is not independently verified.' : 'Scheduled polling is disabled or suppressed during prelaunch.' }}</p>
    <section class="panel form-stack"><h2>Source configuration</h2>
        @foreach($sources as $source)
            <article class="item-row"><div><h3>{{ $source->name }}</h3><p>{{ !$source->enabled ? 'Disabled' : ($source->reuse !== 'summary-permitted' ? 'Reuse not approved' : ($source->feed_url ? 'Feed configured' : 'No feed configured')) }}</p>
                @if($source->feed_url)<p class="field-note">{{ $source->poll_failures }} consecutive failures (capped at 4) · Next eligible check: {{ $source->next_poll_at?->timezone('America/Winnipeg')->format('M j, Y · g:i a') ?? 'Not scheduled' }}</p>@endif</div><a class="text-link" href="/admin/news/feed-health?source_id={{ $source->id }}">View checks</a></article>
        @endforeach
    </section>
    <section class="panel form-stack"><h2>Recorded checks</h2>
        <form class="saved-search" method="get" action="/admin/news/feed-health">
            <label>Source<select name="source_id"><option value="">All sources</option>@foreach($sources as $source)<option value="{{ $source->id }}" @selected((int) ($filters['source_id'] ?? 0) === $source->id)>{{ $source->name }}</option>@endforeach</select></label>
            <label>Result<select name="status"><option value="">All results</option>@foreach(['success','failed'] as $status)<option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ ucfirst($status) }}</option>@endforeach</select></label>
            <label>Period<select name="period">@foreach([1,7,30,90] as $days)<option value="{{ $days }}" @selected($days === $period)>Last {{ $days }} days</option>@endforeach</select></label>
            <button class="button button-primary">Filter checks</button><a class="text-link" href="/admin/news/feed-health">Reset</a>
        </form>
        <p class="field-note">{{ $counts['success'] ?? 0 }} successful checks · {{ $counts['failed'] ?? 0 }} failed checks · {{ $checks->total() }} matching records</p>
        @forelse($checks as $check)
            <article class="item-row"><div><h3>{{ $check->name }}</h3><p>{{ ucfirst($check->status) }} · New candidates: {{ $check->new_candidates }}</p><p class="field-note">Reference #{{ $check->id }} · {{ \Illuminate\Support\Carbon::parse($check->created_at)->timezone('America/Winnipeg')->format('M j, Y · g:i a') }}</p></div></article>
        @empty<p>No checks match the selected filters.</p>@endforelse
        {{ $checks->links() }}
    </section>
</x-layouts.admin>
