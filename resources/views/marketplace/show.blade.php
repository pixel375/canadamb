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
                                    <button type="button" class="button button-outline" data-gallery-close aria-label="Close photo viewer">Close</button>
                                </div>
                                <div class="listing-lightbox-stage">
                                    <button type="button" class="listing-gallery-arrow" data-gallery-prev aria-label="Previous photo">‹</button>
                                    <img data-gallery-preview alt="">
                                    <button type="button" class="listing-gallery-arrow" data-gallery-next aria-label="Next photo">›</button>
                                </div>
                                <p class="listing-lightbox-hint">Use the arrow keys to browse, or Escape to close.</p>
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
                <section class="panel seller-panel">
                    <h2>Seller</h2>
                    <p class="seller-name">{{ $item->seller->name }}</p>
                    <p class="seller-since">Member since {{ $item->seller->created_at->format('F Y') }}</p>
                    @auth
                        @if($item->user_id === auth()->id())
                            <p class="field-note">This is your listing.</p>
                            <a class="button button-primary seller-action" href="/account/listings/{{ $item->id }}/edit">Edit listing</a>
                            <a class="button button-outline seller-action" href="/account/listings">Manage my listings</a>
                            <form method="post" action="/account/listings/{{ $item->id }}/status">
                                @csrf
                                <input type="hidden" name="status" value="sold">
                                <button class="button button-outline seller-action" type="submit">Mark as sold</button>
                            </form>
                        @else
                            <form class="form-stack seller-contact" method="post" action="/marketplace/{{ $item->id }}/message">
                                @csrf
                                <label>Message the seller<textarea name="body" maxlength="2000" rows="3" required placeholder="Hi, is this still available?">{{ old('body') }}</textarea></label>
                                <button class="button button-primary">Send message</button>
                            </form>
                            <form method="post" action="/marketplace/{{ $item->id }}/save">
                                @csrf<button class="button button-outline seller-action">Save listing</button>
                            </form>
                            <details class="seller-report"><summary>Report listing</summary>
                                <form class="form-stack mt-2" method="post" action="/marketplace/{{ $item->id }}/report">
                                    @csrf
                                    <label>Reason<textarea name="reason" minlength="8" maxlength="500" required>{{ old('reason') }}</textarea></label>
                                    <button class="button button-outline">Submit report</button>
                                </form>
                            </details>
                        @endif
                    @else
                        <p class="field-note">To contact the seller, sign in to your CanadaMB account. Your email address is not displayed publicly.</p>
                        <a class="button button-primary seller-action" href="{{ $currentSite->isNetwork() ? '/login' : '/auth/start' }}">Sign in to message</a>
                    @endauth
                </section>
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
                        <h2>Recently listed nearby</h2>
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
