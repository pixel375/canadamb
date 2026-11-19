@props(['item', 'preview' => false])
<article class="listing-card">
    <div class="listing-image">
        @if(!$preview && ($image = $item->media->first()))
            <img src="/marketplace/{{ $item->id }}/thumbnail/{{ $image->id }}" alt="" loading="lazy">
        @else<x-icon name="tag" size="30"/>@endif
    </div>
    <div class="listing-content">
        <p class="listing-price">{{ $item->priceLabel() }}</p>
        <h3>@if($preview){{ $item->title }}@else<a href="/marketplace/{{ $item->id }}">{{ $item->title }}</a>@endif</h3>
        <p class="listing-meta"><x-icon name="pin" size="14"/> {{ $item->location->name }} <span aria-hidden="true">·</span> {{ $item->published_at->diffForHumans() }}</p>
    </div>
</article>
