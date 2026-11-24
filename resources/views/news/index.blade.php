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
        <div class="panel">
            @forelse($items as $article)
                <article class="item-row"><div><p class="section-label">{{ \App\Support\News::CATEGORIES[$article->category] }} · {{ $article->published_at->format('M j, Y') }}</p><h2><a href="/news/{{ $article->id }}">{{ $article->title }}</a></h2><p>{{ \Illuminate\Support\Str::limit($article->body, 175) }}</p></div></article>
            @empty<p>{{ filled($filter['q'] ?? null) ? 'No published news matches your search.' : 'No published news in this section yet.' }}</p>@endforelse
        </div>
        {{ $items->links() }}
        </div>
        </div>
    </div>
</x-layouts.site>
