@php($structured = ['@context' => 'https://schema.org', '@type' => 'Article', 'headline' => $item->title, 'url' => $canonical, 'datePublished' => $item->published_at->toAtomString(), 'dateModified' => $item->updated_at->toAtomString(), 'citation' => $item->source_url])
<x-layouts.site :title="$item->title.' | News in '.($item->location?->name ?? 'Manitoba')" :description="\Illuminate\Support\Str::limit($item->body, 155)" :canonical="$canonical" :structured-data="$structured">
    <div class="container page-shell">
        <?php
            $site = request()->attributes->get('site');
            $newsUrl = \App\Support\LocationScope::browseUrl($site, '/news', \App\Support\LocationScope::currentBrowsing(request()->attributes->get('site')));
            $categoryUrl = '/news?'.http_build_query(\App\Support\LocationScope::preserveAll(array_filter(['category' => $item->category, 'location' => $site->isNetwork() ? \App\Support\LocationScope::currentBrowsing($site)?->slug : null])));
        ?>
        <nav class="discussion-breadcrumb" aria-label="News navigation"><a class="text-link" href="{{ $newsUrl }}">Local news</a><x-icon name="chevron" size="14"/><a class="text-link" href="{{ $categoryUrl }}">{{ \App\Support\News::CATEGORIES[$item->category] }}</a></nav>
        <article class="public-profile-layout news-article-layout">
            <div class="public-profile-main">
                <section class="panel news-article-main">
                    <p class="section-label">{{ \App\Support\News::CATEGORIES[$item->category] }} · {{ $item->location?->name ?? 'Manitoba' }}</p>
                    <h1>{{ $item->title }}</h1>
                    <div class="news-article-byline"><span><x-icon name="news" size="16"/> {{ $item->source->name }}</span><time datetime="{{ $item->published_at->toAtomString() }}">Published {{ $item->published_at->timezone('America/Winnipeg')->format('M j, Y · g:i a T') }}</time></div>
                    <p class="prose-text news-article-body">{{ $item->body }}</p>
                    <div class="news-source-footer"><p class="field-note">Source-attributed summary. Refer to the original notice or report for full context.</p><a class="button button-outline" href="{{ $item->source_url }}" rel="nofollow">Read original source <x-icon name="arrow" size="16"/></a></div>
                </section>
            </div>
            <aside class="public-profile-aside">
                <section class="panel form-stack">
                    <h2>Sources and dates</h2>
                    <div><p class="section-label">Original publisher</p><a class="text-link" href="{{ $item->source_url }}" rel="nofollow">{{ $item->source->name }} <x-icon name="arrow" size="16"/></a></div>
                    <div><p class="section-label">Source dated</p><p>{{ $item->source_date->format('M j, Y') }}</p></div>
                    <div><p class="section-label">Published on this portal</p><p>{{ $item->published_at->timezone('America/Winnipeg')->format('M j, Y') }}</p></div>
                    @if($item->corroboration_url)<a class="text-link" href="{{ $item->corroboration_url }}" rel="nofollow">Corroborating source <x-icon name="arrow" size="16"/></a>@endif
                    @if($item->sensitive)<p class="field-note">For current safety or weather information, check the original official source. Conditions may change.</p>@endif
                </section>
                @if($related->isNotEmpty())
                    <section class="panel discussion-related"><h2>Related local news</h2>@foreach($related as $article)<div><a class="text-link" href="{{ \App\Support\LocationScope::detailUrl('/news/'.$article->id) }}">{{ $article->title }}</a><p class="field-note">{{ $article->source->name }} · {{ $article->published_at->timezone('America/Winnipeg')->format('M j, Y') }}</p></div>@endforeach</section>
                @endif
                <a class="button button-outline" href="{{ $newsUrl }}">Browse more local news</a><a class="text-link" href="/contact?topic=news">Suggest a correction</a>
            </aside>
        </article>
    </div>
</x-layouts.site>
