<x-layouts.site title="Real Estate in Manitoba">
    @php
        $site = request()->attributes->get('site');
        $browseLink = static function (array $params = []) use ($filters, $site): string {
            if ($site->isNetwork() && isset($filters['location'])) {
                $params['location'] = $filters['location'];
            }
            $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
            return '/real-estate'.($query === '' ? '' : '?'.$query);
        };
        $types = [
            ['key' => 'house', 'name' => 'Houses', 'subtitle' => 'Detached homes', 'icon' => 'house'],
            ['key' => 'condo', 'name' => 'Condos', 'subtitle' => 'Condo living', 'icon' => 'building'],
            ['key' => 'apartment', 'name' => 'Apartments', 'subtitle' => 'Apartment homes', 'icon' => 'building'],
            ['key' => 'manufactured-home', 'name' => 'Mobile homes', 'subtitle' => 'Manufactured homes', 'icon' => 'home'],
            ['key' => 'acreage', 'name' => 'Acreages', 'subtitle' => 'Space to grow', 'icon' => 'map'],
            ['key' => 'vacant-land', 'name' => 'Land', 'subtitle' => 'Lots and land', 'icon' => 'map'],
            ['key' => 'farm', 'name' => 'Farms', 'subtitle' => 'Rural properties', 'icon' => 'tractor'],
            ['key' => 'commercial', 'name' => 'Commercial', 'subtitle' => 'Business property', 'icon' => 'building'],
        ];
    @endphp
    <div class="container page-shell">
        <a class="text-link mb-4" href="/marketplace">Marketplace categories</a>
        <section class="property-hero" aria-labelledby="property-heading">
            <div>
                <p class="section-label"><x-icon name="pin" size="16"/> {{ $selectedLocation?->name ?? $site->location?->name ?? 'Manitoba' }} · Homes and rentals</p>
                <h1 id="property-heading">Real Estate</h1>
                <p>Find a place to call home. Explore houses, apartments, acreages, land and commercial properties across {{ $selectedLocation?->name ?? $site->location?->name ?? 'Manitoba' }}.</p>
                <p class="field-note mt-3">Our Real Estate section is taking shape. Public property advertisements are not open yet.</p>
            </div>
            <div class="property-hero-art" aria-hidden="true"><x-icon name="house" size="110"/></div>
        </section>

        <nav class="property-tabs" aria-label="Real Estate transaction type">
            <a href="{{ $browseLink() }}" aria-current="{{ isset($filters['transaction']) ? 'false' : 'page' }}">All properties</a>
            <a href="{{ $browseLink(['transaction' => 'sale']) }}" aria-current="{{ ($filters['transaction'] ?? '') === 'sale' ? 'page' : 'false' }}">For sale</a>
            <a href="{{ $browseLink(['transaction' => 'rent']) }}" aria-current="{{ ($filters['transaction'] ?? '') === 'rent' ? 'page' : 'false' }}">For rent</a>
        </nav>

        <section class="property-discover-section" aria-labelledby="property-types-heading">
            <h2 id="property-types-heading">Browse by property type</h2>
            <div class="property-type-grid">
                @foreach($types as $type)
                    <a class="property-type-card" href="{{ $browseLink(array_filter(['property_type' => $type['key'], 'transaction' => $filters['transaction'] ?? null])) }}" aria-current="{{ ($filters['property_type'] ?? '') === $type['key'] ? 'page' : 'false' }}">
                        <span class="property-type-icon" aria-hidden="true"><x-icon :name="$type['icon']" size="23"/></span>
                        <strong>{{ $type['name'] }}</strong>
                        <small>{{ $type['subtitle'] }}</small>
                    </a>
                @endforeach
            </div>
        </section>

        <details class="property-search-panel" {{ count($filters) > 0 ? 'open' : '' }}>
            <summary><x-icon name="search" size="18"/> Search and filter Real Estate <x-icon name="chevron-down" size="17"/></summary>
            <form action="/real-estate" method="get" class="panel filter-bar" role="search">
                <label>Keywords<input type="search" name="q" maxlength="100" value="{{ $filters['q'] ?? '' }}" placeholder="Search homes and land"></label>
                <label>Listing type<select name="transaction"><option value="">Sale or rent</option><option value="sale" @selected(($filters['transaction'] ?? '') === 'sale')>For sale</option><option value="rent" @selected(($filters['transaction'] ?? '') === 'rent')>For rent</option></select></label>
                <label>Property type
                    <select name="property_type">
                        <option value="">All types</option>
                        @foreach(\App\Models\PropertyListing::TYPES as $key => $label)
                            <option value="{{ $key }}" @selected(($filters['property_type'] ?? '') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                @if($site->isNetwork())
                    <label>Community
                        <select name="location">
                            <option value="">All Manitoba</option>
                            @foreach($locations as $place)
                                <option value="{{ $place->slug }}" @selected(($filters['location'] ?? '') === $place->slug)>{{ $place->name }}</option>
                            @endforeach
                        </select>
                    </label>
                @endif
                <label>Minimum price (CAD)<input type="number" name="min_price" min="0" max="1000000000" step=".01" value="{{ $filters['min_price'] ?? '' }}" placeholder="No minimum"></label>
                <label>Maximum price (CAD)<input type="number" name="max_price" min="0" max="1000000000" step=".01" value="{{ $filters['max_price'] ?? '' }}" placeholder="No maximum"></label>
                <label>Minimum bedrooms<input type="number" name="bedrooms" min="0" max="100" value="{{ $filters['bedrooms'] ?? '' }}" placeholder="Any"></label>
                <label>Sort by<select name="sort"><option value="newest" @selected(($filters['sort'] ?? 'newest') === 'newest')>Newest</option><option value="price_asc" @selected(($filters['sort'] ?? '') === 'price_asc')>Lowest price</option><option value="price_desc" @selected(($filters['sort'] ?? '') === 'price_desc')>Highest price</option><option value="largest" @selected(($filters['sort'] ?? '') === 'largest')>Largest floor area</option></select></label>
                <div class="inline-actions">
                    <button type="submit" class="button button-primary"><x-icon name="search" size="17"/> Apply filters</button>
                    <a class="text-link" href="/real-estate">Clear filters</a>
                </div>
            </form>
        </details>

        <section aria-labelledby="property-results-heading">
            <div class="property-section-heading">
                <div><p class="section-label">Available property listings</p><h2 id="property-results-heading">Browse properties</h2></div>
                @if($publicEnabled)
                    <span class="discussion-count">{{ $items->total() }} results</span>
                @endif
            </div>
            @if(! $publicEnabled)
                <div class="property-empty">
                    <span aria-hidden="true"><x-icon name="house" size="24"/></span>
                    <div>
                        <h3>Property listings are being prepared</h3>
                        <p>Browse the available categories and filters while we prepare the advertising and verification process. No properties are being advertised here yet.</p>
                        <a class="text-link" href="/marketplace">Browse other Marketplace listings <x-icon name="arrow" size="16"/></a>
                    </div>
                </div>
            @elseif($items->isEmpty())
                <div class="property-empty">
                    <span aria-hidden="true"><x-icon name="search" size="24"/></span>
                    <div><h3>No properties match these filters</h3><p>Try a different property type, location, or price range.</p><a class="text-link" href="/real-estate">Clear all filters</a></div>
                </div>
            @else
                <div class="property-result-grid">
                    @foreach($items as $item)
                        <article class="property-result-card">
                            <a href="/real-estate/{{ $item->id }}" aria-label="View {{ $item->title }}" class="property-result-image">
                                @if($item->photos->isNotEmpty())
                                    <img src="/real-estate/{{ $item->id }}/photo/{{ $item->photos->first()->id }}" alt="" loading="lazy">
                                @else
                                    <x-icon name="house" size="43"/>
                                @endif
                            </a>
                            <div class="property-result-body">
                                <p class="section-label">{{ $item->transaction === 'rent' ? 'For rent' : 'For sale' }} · {{ $item->location->name }}</p>
                                <h3><a href="/real-estate/{{ $item->id }}">{{ $item->title }}</a></h3>
                                <strong class="property-result-price">${{ number_format($item->price_cents / 100) }}{{ $item->transaction === 'rent' ? ' / month' : '' }}</strong>
                                <p class="property-result-meta">
                                    {{ \App\Models\PropertyListing::TYPES[$item->property_type] }}
                                    {{ $item->bedrooms === null ? '' : ' · '.$item->bedrooms.' bed' }}
                                    {{ $item->bathrooms === null ? '' : ' · '.$item->bathrooms.' bath' }}
                                </p>
                            </div>
                        </article>
                    @endforeach
                </div>
                {{ $items->links() }}
            @endif
        </section>
    </div>
</x-layouts.site>
