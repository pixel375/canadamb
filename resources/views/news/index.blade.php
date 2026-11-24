<x-layouts.site title="Local news" :location="$place">
    <div class="container page-shell browse-page">
        <div class="page-intro"><div><h1>Local news</h1><p>Source-attributed news from {{ $place?->name ?? 'Manitoba' }}.</p></div></div>
        <div class="browse-workspace">
        <details class="search-disclosure browse-sidebar" @if(!empty($filter)) open @endif>
            <summary><x-icon name="search" size="18"/> News filters <x-icon class="disclosure-arrow" name="chevron-down" size="17"/></summary>
            <form method="get" action="/news" class="panel filter-bar">
            <label>Search news<input type="search" name="q" maxlength="100" value="{{ $filter['q'] ?? '' }}" placeholder="Search headlines and articles"></label>
            <label>Category<select name="category"><option value="">All categories</option>@foreach(\App\Support\News::CATEGORIES as $id => $label)<option value="{{ $id }}" @selected(($filter['category'] ?? null) === $id)>{{ $label }}</option>@endforeach</select></label>
            @if($places->isNotEmpty())<label>Location<select name="location"><option value="">All Manitoba</option>@foreach($places as $option)<option value="{{ $option->slug }}" @selected($place?->id === $option->id)>{{ $option->name }}</option>@endforeach</select></label>@endif
            <button class="button button-primary">Filter news</button>
        </form>
        </details>
        <div class="browse-results-column">
        <section aria-labelledby="news-results-heading">
            <div class="market-results-heading">
                <div><p class="section-label">Community reporting</p><h2 id="news-results-heading">Latest news</h2></div>
                <span class="discussion-count">{{ $items->total() }} {{ \Illuminate\Support\Str::plural('article', $items->total()) }}</span>
            </div>
            @if($items->isEmpty())
                <div class="market-results-empty">
                    <span class="empty-icon" aria-hidden="true"><x-icon name="news" size="24"/></span>
                    <h3>{{ filled($filter['q'] ?? null) ? 'No published news matches your search.' : 'No published news in this section yet.' }}</h3>
                    <p>Check back for local updates or try a different keyword or category.</p>
                    @if(filled($filter['q'] ?? null) || filled($filter['category'] ?? null))
                        <a class="button button-outline" href="{{ \App\Support\LocationScope::browseUrl(request()->attributes->get('site'), '/news', $place) }}">Clear search filters</a>
                    @endif
                </div>
            @else
                <div class="browse-feed">
                    @foreach($items as $article)
                        <article class="panel">
                            <div class="item-row"><div>
                                <p class="section-label">{{ \App\Support\News::CATEGORIES[$article->category] }} · {{ $article->published_at->format('M j, Y') }}</p>
                                <h2><a href="/news/{{ $article->id }}">{{ $article->title }}</a></h2>
                                <p>{{ \Illuminate\Support\Str::limit($article->body, 240) }}</p>
                                <p class="field-note mt-2">{{ $article->source->name }} · {{ $article->location?->name ?? 'Manitoba' }}</p>
                                <a class="text-link mt-2" href="/news/{{ $article->id }}">Read article <x-icon name="arrow" size="16"/></a>
                            </div></div>
                        </article>
                    @endforeach
                </div>
            @endif
        </section>
        {{ $items->links() }}
        </div>
        </div>
    </div>
</x-layouts.site>
