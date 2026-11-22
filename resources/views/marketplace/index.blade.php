<x-layouts.site title="Marketplace">
    <div class="container page-shell">
        <div class="page-intro">
            <div><h1>Marketplace</h1><p>Browse listings in {{ request()->attributes->get('site')->location?->name ?? 'Manitoba' }}.</p></div>
            <div class="inline-actions">
                @auth<a class="button button-outline" href="/account/saved">Saved listings</a>@endauth
                <a class="button button-primary" href="/marketplace/new"><x-icon name="plus" size="16"/> Post a listing</a>
            </div>
        </div>
        <details class="search-disclosure" @if($filters || $errors->any()) open @endif>
            <summary>
                <x-icon name="search" size="18"/>
                <span>Search Marketplace @if($activeFilterCount) <small>({{ $activeFilterCount }} active {{ \Illuminate\Support\Str::plural('filter', $activeFilterCount) }})</small> @endif</span>
                <x-icon class="disclosure-arrow" name="chevron-down" size="17"/>
            </summary>
            <form class="panel filter-bar" method="get" action="/marketplace">
                <label>Search<input type="search" name="q" maxlength="100" value="{{ $filters['q'] ?? '' }}" placeholder="Search listings"></label>
                <label>Category<select name="category"><option value="">All categories</option>@foreach($categories as $key => $label)<option value="{{ $key }}" @selected(($filters['category'] ?? '') === $key)>{{ $label }}</option>@endforeach</select></label>
                @if($locations->isNotEmpty())<label>Location<select name="location"><option value="">All Manitoba</option>@foreach($locations as $place)<option value="{{ $place->slug }}" @selected(($filters['location'] ?? '') === $place->slug)>{{ $place->name }}</option>@endforeach</select></label>@endif
                <label>Condition<select name="condition"><option value="">Any condition</option>@foreach(\App\Support\Marketplace::CONDITIONS as $key => $label)<option value="{{ $key }}" @selected(($filters['condition'] ?? '') === $key)>{{ $label }}</option>@endforeach</select></label>
                <label>Min price (CAD)<input type="number" name="min_price" value="{{ $filters['min_price'] ?? '' }}" min="0" max="9999999.99" step="0.01" placeholder="No minimum"></label>
                <label>Max price (CAD)<input type="number" name="max_price" value="{{ $filters['max_price'] ?? '' }}" min="0" max="9999999.99" step="0.01" placeholder="No maximum"></label>
                <label>Sort by
                    <select name="sort">
                        @foreach($sortOptions as $key => $label)
                            <option value="{{ $key }}" @selected(($filters['sort'] ?? 'newest') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <div class="inline-actions">
                    <button class="button button-primary" type="submit"><x-icon name="search" size="16"/> Search</button>
                    @if($filters)<a class="text-link" href="/marketplace">Clear filters</a>@endif
                </div>
            </form>
        </details>
        @if($items->isEmpty())
            <div class="empty-state"><span class="empty-icon"><x-icon name="tag"/></span><div><h2>No listings found</h2><p>{{ $activeFilterCount ? 'Try a broader search or clear the filters.' : 'Check back later or post a listing of your own.' }}</p></div></div>
        @else
            <div class="listing-grid">@foreach($items as $item)<x-listing-card :item="$item"/>@endforeach</div>
            {{ $items->links() }}
        @endif
    </div>
</x-layouts.site>
