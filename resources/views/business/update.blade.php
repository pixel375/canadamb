<x-layouts.site :title="$item->title">
    <div class="container page-shell narrow">
        <a class="text-link" href="/business-updates">Business updates</a>
        <article class="panel">
            <p class="section-label">{{ ucfirst($item->type) }} · {{ $item->branch->location->name }}</p>
            <h1>{{ $item->title }}</h1>
            <p><a class="text-link" href="/businesses/{{ $item->branch->business->slug }}">{{ $item->branch->business->name }}</a>
                · <time datetime="{{ $item->published_at->toIso8601String() }}">{{ $item->published_at->timezone('America/Winnipeg')->format('M j, Y') }}</time></p>
            <p class="prose-text">{{ $item->body }}</p>
            <x-content-report-form type="business-update" :target="$item->id" :author="$item->author_id" label="business update"/>
        </article>
    </div>
</x-layouts.site>
