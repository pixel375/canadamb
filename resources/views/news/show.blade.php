@php($structured = ['@context' => 'https://schema.org', '@type' => 'Article', 'headline' => $item->title, 'url' => $canonical, 'datePublished' => $item->published_at->toAtomString(), 'dateModified' => $item->updated_at->toAtomString(), 'citation' => $item->source_url])
<x-layouts.site :title="$item->title" :description="\Illuminate\Support\Str::limit($item->body, 155)" :canonical="$canonical" :structured-data="$structured">
    <div class="container page-shell narrow">
        <a href="/news" class="text-link mb-4">Local news</a>
        <article class="panel">
            <p class="section-label">{{ \App\Support\News::CATEGORIES[$item->category] }} · {{ $item->location?->name ?? 'Manitoba' }}</p>
            <h1>{{ $item->title }}</h1>
            <time datetime="{{ $item->published_at->toAtomString() }}">Published {{ $item->published_at->format('M j, Y') }}</time>
            <p class="prose-text">{{ $item->body }}</p>
            <p><a class="text-link" href="{{ $item->source_url }}" rel="nofollow">{{ $item->source->name }}</a> · Source dated {{ $item->source_date->format('M j, Y') }}</p>
            @if($item->corroboration_url)<a class="text-link mt-2" href="{{ $item->corroboration_url }}" rel="nofollow">Corroborating source</a>@endif
        </article>
    </div>
</x-layouts.site>
