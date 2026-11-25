<x-layouts.site title="Saved jobs">
    <div class="container page-shell">
        <div class="page-intro"><div><a class="text-link" href="/account/profile">My account</a><h1>Saved jobs</h1><p>Open positions you have bookmarked.</p></div><a class="button button-outline" href="/jobs">Browse jobs</a></div>
        <x-saved-navigation current="jobs" :total="$total"/>
        <form class="panel saved-search" method="get" action="/account/saved-jobs">
            <label>Search saved jobs<input type="search" name="q" maxlength="100" value="{{ $filters['q'] ?? '' }}" placeholder="Title or description"></label>
            <label>Sort by<select name="sort"><option value="recent" @selected(($filters['sort'] ?? 'recent') === 'recent')>Recently published</option><option value="closing" @selected(($filters['sort'] ?? '') === 'closing')>Closing soonest</option></select></label>
            <button class="button button-primary">Search</button><a class="text-link" href="/account/saved-jobs">Reset</a>
        </form>
        <p class="field-note seller-results-count">{{ $items->total() }} {{ \Illuminate\Support\Str::plural('job', $items->total()) }} found · Closed or unpublished jobs are hidden.</p>
        <div class="panel">
            @forelse($items as $item)<x-job-result :item="$item" :removable="true" :show-description="true"/>@empty<div class="market-results-empty"><h3>{{ $total ? 'No saved jobs match your search.' : 'No active saved jobs on this portal.' }}</h3><a class="button button-outline" href="{{ $total ? '/account/saved-jobs' : '/jobs' }}">{{ $total ? 'View all saved jobs' : 'Browse jobs' }}</a></div>@endforelse
        </div>
        {{ $items->links() }}
        @if($unavailable->total())
            <section class="panel form-stack mt-4"><h2>Unavailable saved jobs ({{ $unavailable->total() }})</h2>
                <p class="field-note">Closed or unpublished positions. Private titles and details are not shown.</p>
                @foreach($unavailable as $record)
                    <div class="item-row"><span>Job #{{ $record->job_posting_id }} · Unavailable</span><form method="post" action="/jobs/{{ $record->job_posting_id }}/save">@csrf @method('DELETE')<button class="button button-outline">Remove unavailable job</button></form></div>
                @endforeach
                {{ $unavailable->links() }}
            </section>
        @endif
    </div>
</x-layouts.site>
