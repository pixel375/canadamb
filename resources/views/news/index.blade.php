<x-layouts.site title="Local news">
    <div class="container page-shell narrow">
        <div class="page-intro"><div><h1>Local news</h1><p>Source-attributed news from {{ request()->attributes->get('site')->location?->name ?? 'Manitoba' }}.</p></div></div>
        <form method="get" action="/news" class="panel filter-bar">
            <label>Category<select name="category"><option value="">All categories</option>@foreach(\App\Support\News::CATEGORIES as $id => $label)<option value="{{ $id }}" @selected(($filter['category'] ?? null) === $id)>{{ $label }}</option>@endforeach</select></label>
            <button class="button button-primary">Filter news</button>
        </form>
        <div class="panel">
            @forelse($items as $article)
                <article class="item-row"><div><p class="section-label">{{ \App\Support\News::CATEGORIES[$article->category] }} · {{ $article->published_at->format('M j, Y') }}</p><h2><a href="/news/{{ $article->id }}">{{ $article->title }}</a></h2><p>{{ \Illuminate\Support\Str::limit($article->body, 175) }}</p></div></article>
            @empty<p>No published news in this section yet.</p>@endforelse
        </div>
        {{ $items->links() }}
    </div>
</x-layouts.site>
