<x-layouts.site title="Business updates" :location="$place">
    <div class="container page-shell browse-page">
        <div class="page-intro"><div><h1>Business updates</h1><p>Local announcements, promotions and news from businesses in {{ $place?->name ?? 'Manitoba' }}.</p></div><a class="button button-outline" href="{{ \App\Support\LocationScope::browseUrl(request()->attributes->get('site'), '/businesses', $place) }}">Business directory</a></div>
        <div class="browse-workspace">
            <details class="search-disclosure browse-sidebar" @if(!empty(array_filter($filters))) open @endif>
                <summary><x-icon name="search" size="18"/> Filter updates <x-icon class="disclosure-arrow" name="chevron-down" size="17"/></summary>
                <form class="panel filter-bar" action="/business-updates" method="get">
                    <label>Search updates<input type="search" name="q" maxlength="100" value="{{ $filters['q'] ?? '' }}" placeholder="Offers, news and announcements"></label>
                    <label>Type<select name="type"><option value="">All updates</option>@foreach(['announcement' => 'Announcements', 'promotion' => 'Promotions', 'update' => 'General updates'] as $value => $label)<option value="{{ $value }}" @selected(($filters['type'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></label>
                    @if($places->isNotEmpty())<label>Location<select name="location"><option value="">All Manitoba</option>@foreach($places as $option)<option value="{{ $option->slug }}" @selected($place?->id === $option->id)>{{ $option->name }}</option>@endforeach</select></label>@endif
                    <button class="button button-primary" type="submit">Filter updates</button>
                </form>
            </details>
            <div class="browse-results-column">
                <div class="market-results-heading"><div><p class="section-label">Business activity</p><h2>Latest from {{ $place?->name ?? 'Manitoba' }}</h2></div><span class="discussion-count">{{ $updates->total() }} {{ \Illuminate\Support\Str::plural('update', $updates->total()) }}</span></div>
                <div class="browse-feed">
                    @forelse($updates as $update)
                        <article class="panel business-update-card">
                            <div class="business-update-heading"><span class="business-category-icon"><x-icon name="building" size="20"/></span><div><a class="text-link" href="{{ \App\Support\LocationScope::detailUrl('/businesses/'.$update->branch->business->slug) }}">{{ $update->branch->business->name }}</a><p class="field-note">{{ $update->branch->location->name }} · {{ $update->published_at->format('M j, Y') }}</p></div><span class="member-listing-status">{{ ucfirst($update->type) }}</span></div>
                            <h3>{{ $update->title }}</h3><p class="prose-text">{{ $update->body }}</p>
                        </article>
                    @empty
                        <div class="panel market-results-empty"><x-icon name="news" size="28"/><h3>{{ filled($filters['q'] ?? null) || filled($filters['type'] ?? null) ? 'No matching business updates.' : 'No published business updates yet.' }}</h3><p>Try another search or discover businesses in the directory.</p><div class="inline-actions"><a class="button button-outline" href="{{ \App\Support\LocationScope::browseUrl(request()->attributes->get('site'), '/business-updates', $place) }}">Clear filters</a><a class="button button-primary" href="{{ \App\Support\LocationScope::browseUrl(request()->attributes->get('site'), '/businesses', $place) }}">Browse businesses</a></div></div>
                    @endforelse
                </div>
                {{ $updates->links() }}
            </div>
        </div>
    </div>
</x-layouts.site>
