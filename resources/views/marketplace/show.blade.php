<x-layouts.site :title="$item->title.' | Marketplace'" :description="\Illuminate\Support\Str::limit($item->description, 155)" :canonical="$canonical">
    <div class="container page-shell">
        <a class="text-link" href="/marketplace">Marketplace</a>
        <article class="panel narrow mt-4">
            <p class="section-label">{{ $item->categoryRecord->name }}</p>
            <h1>{{ $item->title }}</h1>
            <p class="listing-price">{{ $item->priceLabel() }} <small>{{ $item->negotiable ? 'Negotiable' : '' }}</small></p>
            <p class="listing-meta"><x-icon name="pin" size="14"/> {{ $item->location->name }} · {{ $item->published_at->diffForHumans() }}</p>
            @if($item->media->isNotEmpty())
                <div class="listing-gallery">
                    @foreach($item->media as $photo)
                        <a href="/marketplace/{{ $item->id }}/photo/{{ $photo->id }}"><img src="/marketplace/{{ $item->id }}/thumbnail/{{ $photo->id }}" alt="{{ $item->title }} photo {{ $loop->iteration }}" loading="lazy"></a>
                    @endforeach
                </div>
            @endif
            <p class="prose-text">{{ $item->description }}</p>
            <dl class="attributes-list">
                <div><dt>Condition</dt><dd>{{ \App\Support\Marketplace::CONDITIONS[$item->condition] }}</dd></div>
                @foreach($item->getAttribute('attributes') ?? [] as $field => $value)
                    <div><dt>{{ \App\Support\Marketplace::FIELDS[$item->category][$field] ?? $field }}</dt><dd>{{ $value }}</dd></div>
                @endforeach
            </dl>
            <p>Listed by {{ $item->seller->name }}</p>
        </article>
        @auth
            <div class="panel form-stack narrow">
                <form method="post" action="/marketplace/{{ $item->id }}/save">@csrf<button class="button button-outline">Save listing</button></form>
                @if($item->user_id !== auth()->id())
                    <form class="form-stack" method="post" action="/marketplace/{{ $item->id }}/message">@csrf
                        <label>Message the seller<textarea name="body" maxlength="2000" rows="3" required>{{ old('body') }}</textarea></label>
                        <div><button class="button button-primary">Send message</button></div>
                    </form>
                    <details><summary>Report listing</summary><form class="form-stack mt-2" method="post" action="/marketplace/{{ $item->id }}/report">@csrf
                        <label>Reason<textarea name="reason" minlength="8" maxlength="500" required>{{ old('reason') }}</textarea></label>
                        <div><button class="button button-outline">Submit report</button></div>
                    </form></details>
                @endif
            </div>
        @else
            <p class="mt-3"><a class="text-link" href="/login">Sign in to save this listing or contact the seller.</a></p>
        @endauth
    </div>
</x-layouts.site>
