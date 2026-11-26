@props(['item', 'preview' => false])
<article class="listing-card">
    @if($preview)
        <div class="listing-image">
            <x-icon name="tag" size="30"/>
        </div>
    @else
        <a class="listing-image" href="/marketplace/{{ $item->id }}" aria-label="View {{ $item->title }}">
            @if($image = $item->media->first())
                <img src="/marketplace/{{ $item->id }}/thumbnail/{{ $image->id }}" alt="" loading="lazy">
            @else
                <x-icon name="tag" size="30"/>
            @endif
        </a>
    @endif
    <div class="listing-content">
        <p class="listing-price">{{ $item->priceLabel() }}</p>
        <h3>@if($preview){{ $item->title }}@else<a href="/marketplace/{{ $item->id }}">{{ $item->title }}</a>@endif</h3>
        @php($details = \App\Support\Marketplace::cardDetails($item))
        @if($details)<p class="listing-meta listing-card-details">{{ implode(' · ', $details) }}</p>@endif
        <p class="listing-meta"><x-icon name="pin" size="14"/> {{ $item->location->name }} @if($item->published_at)<span aria-hidden="true">·</span> {{ $item->published_at->diffForHumans() }}@endif</p>
    </div>
</article>
