@php($currentSite = request()->attributes->get('site'))
<x-layouts.site :title="$item->title.' | Marketplace'" :description="\Illuminate\Support\Str::limit($item->description, 155)" :canonical="$canonical">
    <div class="container page-shell">
        <nav class="listing-breadcrumb" aria-label="Breadcrumb">
            <a class="text-link" href="/marketplace">Marketplace</a>
            <span aria-hidden="true">/</span>
            <a class="text-link" href="/marketplace?category={{ rawurlencode($item->category) }}">{{ $item->categoryRecord->name }}</a>
        </nav>
        <div class="listing-detail-layout">
            <article class="panel listing-detail">
                <p class="section-label"><a href="/marketplace?category={{ rawurlencode($item->category) }}">{{ $item->categoryRecord->name }}</a></p>
                <h1>{{ $item->title }}</h1>
                <p class="listing-price">{{ $item->priceLabel() }} <small>{{ $item->negotiable ? 'Negotiable' : '' }}</small></p>
                <p class="listing-meta"><x-icon name="pin" size="14"/> {{ $item->location->name }} <span aria-hidden="true">·</span> Listed {{ $item->published_at->diffForHumans() }}</p>
                @if($item->media->isNotEmpty())
                    <div class="listing-gallery" data-listing-gallery aria-label="Photos of {{ $item->title }}">
                        @foreach($item->media as $photo)
                            <a class="listing-gallery-link" data-gallery-item
                                href="/marketplace/{{ $item->id }}/photo/{{ $photo->id }}"
                                aria-label="View photo {{ $loop->iteration }} of {{ $item->media->count() }} larger">
                                <img src="/marketplace/{{ $item->id }}/{{ $loop->first ? 'photo' : 'thumbnail' }}/{{ $photo->id }}"
                                    alt="{{ $item->title }} — photo {{ $loop->iteration }}"
                                    @if(!$loop->first) loading="lazy" @endif>
                            </a>
                        @endforeach
                        <dialog class="listing-lightbox" data-gallery-dialog aria-label="Listing photo viewer">
                            <div class="listing-lightbox-inner">
                                <div class="listing-lightbox-header">
                                    <span data-gallery-counter aria-live="polite"></span>
                                    <div class="listing-lightbox-navigation">
                                        <button type="button" class="listing-gallery-arrow" data-gallery-prev aria-label="Previous photo" @if($item->media->count() < 2) hidden @endif>‹</button>
                                        <button type="button" class="listing-gallery-arrow" data-gallery-next aria-label="Next photo" @if($item->media->count() < 2) hidden @endif>›</button>
                                    </div>
                                    <button type="button" class="button button-outline" data-gallery-close aria-label="Close photo viewer" autofocus>Close</button>
                                </div>
                                <div class="listing-lightbox-stage" data-gallery-stage>
                                    <img data-gallery-preview alt="" draggable="false">
                                </div>
                                @if($item->media->count() > 1)
                                    <div class="listing-lightbox-thumbnails" role="group" aria-label="Choose a photo">
                                        @foreach($item->media as $photo)
                                            <button class="listing-lightbox-thumb" type="button" data-gallery-thumb
                                                aria-label="Show photo {{ $loop->iteration }} of {{ $item->media->count() }}">
                                                <img src="/marketplace/{{ $item->id }}/thumbnail/{{ $photo->id }}" alt="" loading="lazy">
                                            </button>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        </dialog>
                    </div>
                @endif
                <h2 class="listing-detail-subhead">Description</h2>
                <p class="prose-text">{{ $item->description }}</p>
                <dl class="attributes-list">
                    <div><dt>Condition</dt><dd>{{ \App\Support\Marketplace::CONDITIONS[$item->condition] }}</dd></div>
                    @foreach($item->getAttribute('attributes') ?? [] as $field => $value)
                        <div><dt>{{ \App\Support\Marketplace::FIELDS[$item->category][$field] ?? $field }}</dt><dd>{{ $value }}</dd></div>
                    @endforeach
                </dl>
            </article>
            <aside class="listing-detail-aside" aria-label="Seller and other listings">
                @include('marketplace.seller')
                @if($related->isNotEmpty())
                    <section class="panel listing-suggestions">
                        <h2>Similar listings</h2>
                        <div class="listing-grid listing-grid-compact">
                            @foreach($related as $suggestion)
                                <x-listing-card :item="$suggestion"/>
                            @endforeach
                        </div>
                    </section>
                @endif
                @if($recent->isNotEmpty())
                    <section class="panel listing-suggestions">
                        <h2>Recently listed</h2>
                        <div class="listing-grid listing-grid-compact">
                            @foreach($recent as $suggestion)
                                <x-listing-card :item="$suggestion"/>
                            @endforeach
                        </div>
                    </section>
                @endif
            </aside>
        </div>
    </div>
</x-layouts.site>
