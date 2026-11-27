<x-layouts.site title="Local news" :location="$place">
    <div class="container page-shell browse-page">
        <div class="page-intro"><div><h1>Local news</h1><p>Source-attributed news and public notices from {{ $place?->name ?? 'Manitoba' }}.</p></div></div>
        <div class="browse-workspace">
        <details class="search-disclosure browse-sidebar" @if(!empty($filter)) open @endif>
            <summary><x-icon name="search" size="18"/> News filters <x-icon class="disclosure-arrow" name="chevron-down" size="17"/></summary>
            <form method="get" action="/news" class="panel filter-bar">
            <label>Search news<input type="search" name="q" maxlength="100" value="{{ $filter['q'] ?? '' }}" placeholder="Search headlines and articles"></label>
            <label>Category<select name="category"><option value="">All categories</option>@foreach(\App\Support\News::CATEGORIES as $id => $label)<option value="{{ $id }}" @selected(($filter['category'] ?? null) === $id)>{{ $label }}</option>@endforeach</select></label>
            @if($places->isNotEmpty())<label>Location<select name="location"><option value="">All Manitoba</option>@foreach($places as $option)<option value="{{ $option->slug }}" @selected($place?->id === $option->id)>{{ $option->name }}</option>@endforeach</select></label>@endif
            <label>Source<select name="source"><option value="">All published sources</option>@foreach($sources as $source)<option value="{{ $source->id }}" @selected((string) ($filter['source'] ?? '') === (string) $source->id)>{{ $source->name }}</option>@endforeach</select></label>
            <label>Published<select name="period"><option value="">Any time</option>@foreach([7,30,90] as $days)<option value="{{ $days }}" @selected((string) ($filter['period'] ?? '') === (string) $days)>Last {{ $days }} days</option>@endforeach</select></label>
            <button class="button button-primary">Filter news</button>
        </form>
        </details>
        <div class="browse-results-column">
        <?php
            $categoryParams = \App\Support\LocationScope::preserveAll(array_filter(array_diff_key($filter, ['category' => true]), fn ($value) => $value !== null && $value !== ''));
            $allUrl = '/news'.($categoryParams ? '?'.http_build_query($categoryParams) : '');
            $clearUrl = \App\Support\LocationScope::browseUrl(request()->attributes->get('site'), '/news', $place);
        ?>
        <section class="panel news-category-panel" aria-label="News categories">
            <div class="section-heading"><h2>Browse news topics</h2><a class="text-link" href="{{ $clearUrl }}">Reset filters</a></div>
            <nav class="discussion-category-nav" aria-label="Filter news by topic">
                <a class="discussion-filter-chip" href="{{ $allUrl }}" @if(!filled($filter['category'] ?? null)) aria-current="page" @endif>All news <small>{{ $categoryCounts->sum() }}</small></a>
                @foreach(\App\Support\News::CATEGORIES as $key => $label)
                    <a class="discussion-filter-chip" href="/news?{{ http_build_query(['category' => $key] + $categoryParams) }}" @if(($filter['category'] ?? null) === $key) aria-current="page" @endif>{{ $label }} <small>{{ $categoryCounts[$key] ?? 0 }}</small></a>
                @endforeach
            </nav>
        </section>
        <section aria-labelledby="news-results-heading">
            <div class="market-results-heading">
                <div><p class="section-label">Community reporting</p><h2 id="news-results-heading">{{ \App\Support\News::CATEGORIES[$filter['category'] ?? ''] ?? 'Latest news' }}</h2></div>
                <span class="discussion-count">{{ $items->total() }} {{ \Illuminate\Support\Str::plural('article', $items->total()) }}</span>
            </div>
            @if($items->isEmpty())
                <div class="market-results-empty">
                    <span class="empty-icon" aria-hidden="true"><x-icon name="news" size="24"/></span>
                    <h3>{{ array_filter($filter) ? 'No published news matches your search.' : 'No published news in this section yet.' }}</h3>
                    <p>Check back for local updates or try a different keyword or category.</p>
                    @if(array_diff_key(array_filter($filter), ['location' => true]))
                        <a class="button button-outline" href="{{ \App\Support\LocationScope::browseUrl(request()->attributes->get('site'), '/news', $place) }}">Clear search filters</a>
                    @endif
                </div>
            @else
                <div class="browse-feed">
                    @foreach($items as $article)
                        <article class="panel news-result-card">
                            <div class="news-result-copy">
                                <p class="section-label">{{ \App\Support\News::CATEGORIES[$article->category] }} · {{ $article->location?->name ?? 'Manitoba' }}</p>
                                <h2><a href="{{ \App\Support\LocationScope::detailUrl('/news/'.$article->id) }}">{{ $article->title }}</a></h2>
                                <p>{{ \Illuminate\Support\Str::limit($article->body, 240) }}</p>
                                <p class="field-note mt-2">{{ $article->source->name }} · <time datetime="{{ $article->published_at->toAtomString() }}">{{ $article->published_at->timezone('America/Winnipeg')->format('M j, Y') }}</time></p>
                                <a class="text-link mt-2" href="{{ \App\Support\LocationScope::detailUrl('/news/'.$article->id) }}">Read article <x-icon name="arrow" size="16"/></a>
                            </div>
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
