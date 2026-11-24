<x-layouts.site :title="$item->title.' | Real Estate'" :canonical="$canonical">
    <div class="container page-shell narrow">
        <a class="text-link mb-4" href="/real-estate">Real Estate listings</a>
        <article class="panel">
            <p class="section-label">{{ $item->transaction === 'rent' ? 'For rent' : 'For sale' }} · {{ $item->location->name }}, Manitoba</p>
            <h1>{{ $item->title }}</h1>
            <p class="property-result-price">${{ number_format($item->price_cents / 100) }}{{ $item->transaction === 'rent' ? ' / month' : '' }}</p>
            <div class="property-detail-meta">
                <span>{{ \App\Models\PropertyListing::TYPES[$item->property_type] }}</span>
                @if($item->bedrooms !== null)<span>{{ $item->bedrooms }} bedrooms</span>@endif
                @if($item->bathrooms !== null)<span>{{ $item->bathrooms }} bathrooms</span>@endif
                @if($item->floor_area !== null)<span>{{ number_format($item->floor_area) }} sq ft</span>@endif
                @if($item->acreage !== null)<span>{{ $item->acreage }} acres</span>@endif
            </div>
            @if($item->photos->isNotEmpty())
                <div class="property-detail-images">
                    @foreach($item->photos as $photo)
                        <img src="/real-estate/{{ $item->id }}/photo/{{ $photo->id }}" alt="Property photo {{ $loop->iteration }}" loading="lazy">
                    @endforeach
                </div>
            @endif
            <h2>About this property</h2>
            <p class="prose-text">{{ $item->description }}</p>
            @if($item->year_built)<p>Built in {{ $item->year_built }}.</p>@endif
            @if($item->available_on)<p>Available from {{ $item->available_on->format('M j, Y') }}.</p>@endif
            <p class="field-note">Location: {{ $item->location->name }}, Manitoba. Exact addresses and legal descriptions are not displayed.</p>
        </article>
    </div>
</x-layouts.site>
