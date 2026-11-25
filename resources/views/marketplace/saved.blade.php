<x-layouts.site title="Saved listings">
    <div class="container page-shell">
        <div class="page-intro"><div><a class="text-link" href="/account/profile">My account</a><h1>Saved listings</h1><p>Find active items you saved on this portal.</p></div><a class="button button-outline" href="/marketplace">Browse Marketplace</a></div>
        <x-saved-navigation current="listings" :total="$total"/>
        <form class="panel saved-search" method="get" action="/account/saved">
            <label>Search saved listings<input type="search" name="q" maxlength="100" value="{{ $filters['q'] ?? '' }}" placeholder="Title or description"></label>
            <label>Sort by<select name="sort">@foreach(['recent' => 'Recently published', 'price-low' => 'Lowest price', 'price-high' => 'Highest price'] as $key => $label)<option value="{{ $key }}" @selected(($filters['sort'] ?? 'recent') === $key)>{{ $label }}</option>@endforeach</select></label>
            <button class="button button-primary">Search</button><a class="text-link" href="/account/saved">Reset</a>
        </form>
        <p class="field-note seller-results-count">{{ $items->total() }} {{ \Illuminate\Support\Str::plural('listing', $items->total()) }} found · Only currently public listings are shown.</p>
        <div class="listing-grid">
            @forelse($items as $item)<div class="saved-listing-card"><x-listing-card :item="$item"/><form method="post" action="/marketplace/{{ $item->id }}/save" class="mt-2">@csrf @method('DELETE')<button class="button button-outline">Unsave listing</button></form></div>
            @empty<div class="panel market-results-empty"><span class="empty-icon"><x-icon name="tag" size="24"/></span><h3>{{ $total ? 'No saved listings match your search.' : 'You have no active saved listings here.' }}</h3><a class="button button-primary" href="{{ $total ? '/account/saved' : '/marketplace' }}">{{ $total ? 'View all saved listings' : 'Browse Marketplace' }}</a></div>@endforelse
        </div>
        {{ $items->links() }}
        @if($unavailable->total())
            <section class="panel form-stack mt-4"><h2>Unavailable saved listings ({{ $unavailable->total() }})</h2>
                <p class="field-note">These listings are no longer public or have expired. Private titles and photos are not shown. You may keep the bookmark or remove it.</p>
                @foreach($unavailable as $record)
                    <div class="item-row"><span>Listing #{{ $record->listing_id }} · Unavailable</span><form method="post" action="/marketplace/{{ $record->listing_id }}/save">@csrf @method('DELETE')<button class="button button-outline">Unsave unavailable listing</button></form></div>
                @endforeach
                {{ $unavailable->links() }}
            </section>
        @endif
    </div>
</x-layouts.site>
