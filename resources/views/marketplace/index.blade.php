<x-layouts.site title="Marketplace">
    <div class="container page-shell">
        <div class="page-intro">
            <div><h1>Marketplace</h1><p>Browse listings in {{ request()->attributes->get('site')->location?->name ?? 'Manitoba' }}.</p></div>
            <div class="inline-actions">
                @auth<a class="button button-outline" href="/account/saved">Saved listings</a>@endauth
                <a class="button button-primary" href="/marketplace/new"><x-icon name="plus" size="16"/> Post a listing</a>
            </div>
        </div>
        @if($isDiscovery)
            <section class="market-discovery" aria-labelledby="market-discovery-heading">
                <div class="market-discovery-head">
                    <div>
                        <p class="section-label">Browse classifieds</p>
                        <h2 id="market-discovery-heading">{{ $selectedGroup ? $categoryGroups[$selectedGroup]['name'] : 'What are you looking for?' }}</h2>
                        <p>{{ $selectedGroup ? 'Choose a subcategory, or browse everything in this section.' : 'Start with a category to see listings that interest you.' }}</p>
                    </div>
                    <div class="inline-actions">
                        @if($selectedGroup)
                            <a class="button button-outline" href="/marketplace">All main categories</a>
                            <a class="button button-primary" href="/marketplace?group={{ urlencode($selectedGroup) }}&amp;view=all">View all in {{ $categoryGroups[$selectedGroup]['name'] }}</a>
                        @else
                            <a class="button button-primary" href="/marketplace?view=all">View all listings</a>
                        @endif
                    </div>
                </div>
                @unless($selectedGroup)
                    <form class="market-quick-search" action="/marketplace" method="get" role="search">
                        <x-icon name="search" size="19"/>
                        <label class="sr-only" for="marketplace-quick-query">Search all classifieds</label>
                        <input id="marketplace-quick-query" type="search" name="q" placeholder="Search vehicles, furniture, electronics and more" maxlength="100">
                        <button type="submit" class="button button-primary">Search listings</button>
                    </form>
                @endunless
                @if($selectedGroup)
                    <div class="market-category-grid market-subcategory-grid">
                        @foreach($categoryGroups[$selectedGroup]['available'] as $slug => $name)
                            <a class="market-category-card market-subcategory-card" href="/marketplace?category={{ urlencode($slug) }}">
                                <span class="market-category-name">{{ $name }}</span>
                                <span class="market-category-link">Explore listings <span aria-hidden="true">→</span></span>
                            </a>
                        @endforeach
                    </div>
                @else
                    <div class="market-category-grid">
                        @foreach($categoryGroups as $slug => $group)
                            <a class="market-category-card" href="/marketplace?group={{ urlencode($slug) }}">
                                <span class="market-category-icon" aria-hidden="true">{{ $group['icon'] }}</span>
                                <span class="market-category-name">{{ $group['name'] }}</span>
                                <span class="market-category-description">{{ $group['subtitle'] }}</span>
                                <span class="market-category-link">{{ count($group['available']) }} {{ \Illuminate\Support\Str::plural('subcategory', count($group['available'])) }} <span aria-hidden="true">→</span></span>
                            </a>
                        @endforeach
                        <div class="market-category-card market-category-upcoming" aria-label="Real Estate, coming later">
                            <span class="market-category-icon" aria-hidden="true">🏡</span>
                            <span class="market-category-name">Real Estate</span>
                            <span class="market-category-description">Homes, rentals and land — a dedicated section is planned for a future launch.</span>
                            <span class="market-category-coming">Coming later</span>
                        </div>
                    </div>
                @endif
            </section>
        @else
            <div class="market-browse-context">
                <div class="inline-actions">
                    <a class="text-link" href="/marketplace">All categories</a>
                    @if($selectedGroup)
                        <span aria-hidden="true">/</span>
                        <a class="text-link" href="/marketplace?group={{ urlencode($selectedGroup) }}">{{ $categoryGroups[$selectedGroup]['name'] }}</a>
                    @elseif(isset($filters['category']))
                        @foreach($categoryGroups as $slug => $group)
                            @if(isset($group['available'][$filters['category']]))
                                <span aria-hidden="true">/</span>
                                <a class="text-link" href="/marketplace?group={{ urlencode($slug) }}">{{ $group['name'] }}</a>
                                @break
                            @endif
                        @endforeach
                    @endif
                    @if(isset($filters['category']))
                        <span aria-hidden="true">/</span>
                        <strong>{{ $categories[$filters['category']] }}</strong>
                    @endif
                </div>
                @if(!isset($filters['category']) && !$selectedGroup && !isset($filters['q']))
                    <p class="field-note">Showing all current listings. <a class="text-link" href="/marketplace">Browse categories instead</a></p>
                @endif
            </div>
        @endif
        <details class="search-disclosure" @if(array_diff_key($filters, ['group' => true, 'view' => true]) || $errors->any()) open @endif>
            <summary>
                <x-icon name="search" size="18"/>
                <span>Search Marketplace @if($activeFilterCount) <small>({{ $activeFilterCount }} active {{ \Illuminate\Support\Str::plural('filter', $activeFilterCount) }})</small> @endif</span>
                <x-icon class="disclosure-arrow" name="chevron-down" size="17"/>
            </summary>
            <form class="panel filter-bar" method="get" action="/marketplace">
                <label>Search<input type="search" name="q" maxlength="100" value="{{ $filters['q'] ?? '' }}" placeholder="Search listings"></label>
                <label>Category<select name="category"><option value="">All categories</option>
                    @foreach($categoryGroups as $group)
                        <optgroup label="{{ $group['name'] }}">
                            @foreach($group['available'] as $key => $label)<option value="{{ $key }}" @selected(($filters['category'] ?? '') === $key)>{{ $label }}</option>@endforeach
                        </optgroup>
                    @endforeach
                </select></label>
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
        @unless($isDiscovery)
        @if($items->isEmpty())
            <div class="empty-state"><span class="empty-icon"><x-icon name="tag"/></span><div><h2>No listings found</h2><p>{{ $activeFilterCount ? 'Try a broader search or clear the filters.' : 'Check back later or post a listing of your own.' }}</p></div></div>
        @else
            <div class="listing-grid">@foreach($items as $item)<x-listing-card :item="$item"/>@endforeach</div>
            {{ $items->links() }}
        @endif
        @endunless
    </div>
</x-layouts.site>
