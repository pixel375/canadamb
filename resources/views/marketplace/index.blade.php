<x-layouts.site title="Marketplace">
    @php
        // Carry only a validated provincial location filter through category
        // browsing, never arbitrary query parameters.
        $marketLink = static function (array $params = []) use ($filters): string {
            if (isset($filters['location'])) {
                $params['location'] = $filters['location'];
            }
            $query = http_build_query(\App\Support\LocationScope::preserveAll($params), '', '&', PHP_QUERY_RFC3986);

            return '/marketplace'.($query === '' ? '' : '?'.$query);
        };
        $marketPlace = isset($filters['location']) ? $locations->firstWhere('slug', $filters['location'])?->name : null;
        $postParams = array_intersect_key($filters, ['category' => true, 'location' => true]);
        $postUrl = '/marketplace/new'.($postParams ? '?'.http_build_query($postParams) : '');
    @endphp
    <div class="container page-shell browse-page">
        <div class="page-intro">
            <div><h1>Marketplace</h1><p>Browse listings in {{ $marketPlace ?? request()->attributes->get('site')->location?->name ?? 'Manitoba' }}.</p></div>
            <div class="inline-actions">
                @auth<a class="button button-outline" href="/account/saved">Saved listings</a>@endauth
                <a class="button button-primary" href="{{ $postUrl }}"><x-icon name="plus" size="16"/> Post a listing</a>
            </div>
        </div>
        @if($marketPlace)
            <p class="field-note">Browsing <strong>{{ $marketPlace }}</strong> listings. <a class="text-link" href="/marketplace?location=">See all Manitoba categories</a></p>
        @endif
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
                            <a class="button button-outline" href="{{ $marketLink() }}">All main categories</a>
                            <a class="button button-primary" href="{{ $marketLink(['group' => $selectedGroup, 'view' => 'all']) }}">View all in {{ $categoryGroups[$selectedGroup]['name'] }}</a>
                        @else
                            <a class="button button-primary" href="{{ $marketLink(['view' => 'all']) }}">View all listings</a>
                        @endif
                    </div>
                </div>
                @unless($selectedGroup)
                    <form class="market-quick-search" action="/marketplace" method="get" role="search">
                        @if($marketPlace || \App\Support\LocationScope::explicitAll(request()->attributes->get('site')))<input type="hidden" name="location" value="{{ $filters['location'] ?? '' }}">@endif
                        <x-icon name="search" size="19"/>
                        <label class="sr-only" for="marketplace-quick-query">Search all classifieds</label>
                        <input id="marketplace-quick-query" type="search" name="q" placeholder="Search listings" maxlength="100">
                        <button type="submit" class="button button-primary">Search listings</button>
                    </form>
                @endunless
                @if($selectedGroup)
                    <div class="market-category-grid market-subcategory-grid">
                        @foreach($categoryGroups[$selectedGroup]['available'] as $slug => $name)
                            <a class="market-category-card market-subcategory-card" href="{{ $marketLink(['category' => $slug]) }}">
                                <span class="market-category-name">{{ $name }}</span>
                                <span class="market-category-link">Explore listings <span aria-hidden="true">→</span></span>
                            </a>
                        @endforeach
                    </div>
                @else
                    <div class="market-category-grid">
                        @foreach($categoryGroups as $slug => $group)
                            <article class="market-category-card market-category-parent" aria-labelledby="market-group-{{ $slug }}">
                                <div class="market-parent-header">
                                    <span class="market-category-icon" aria-hidden="true"><x-icon :name="$group['icon']" size="25"/></span>
                                    <div class="market-parent-copy">
                                        <h3 id="market-group-{{ $slug }}" class="market-category-name">
                                            <a href="{{ $marketLink(['group' => $slug]) }}">{{ $group['name'] }}</a>
                                        </h3>
                                        <p class="market-category-description">{{ $group['subtitle'] }}</p>
                                    </div>
                                </div>
                                <nav class="market-category-children" aria-label="{{ $group['name'] }} subcategories">
                                    @foreach($group['available'] as $childSlug => $childName)
                                        <a href="{{ $marketLink(['category' => $childSlug]) }}">{{ $childName }}</a>
                                    @endforeach
                                </nav>
                                <a class="market-category-link market-parent-more" href="{{ $marketLink(['group' => $slug]) }}">
                                    Browse {{ $group['name'] }} <x-icon name="arrow" size="15"/>
                                </a>
                            </article>
                        @endforeach
                        <a class="market-category-card market-category-upcoming" href="{{ \App\Support\LocationScope::browseUrl(request()->attributes->get('site'), '/real-estate', \App\Support\LocationScope::currentBrowsing(request()->attributes->get('site'))) }}" aria-label="Browse Real Estate categories, listings coming later">
                            <span class="market-category-icon" aria-hidden="true"><x-icon name="house" size="25"/></span>
                            <span class="market-category-name">Real Estate</span>
                            <span class="market-category-description">Explore homes, rentals, acreages and land in a dedicated section.</span>
                            <span class="market-category-coming">Coming later</span>
                        </a>
                    </div>
                @endif
            </section>
        @else
            <div class="market-browse-context">
                <div class="inline-actions">
                    <a class="text-link" href="{{ $marketLink() }}">All categories</a>
                    @if($selectedGroup)
                        <span aria-hidden="true">/</span>
                        <a class="text-link" href="{{ $marketLink(['group' => $selectedGroup]) }}">{{ $categoryGroups[$selectedGroup]['name'] }}</a>
                    @elseif(isset($filters['category']))
                        @foreach($categoryGroups as $slug => $group)
                            @if(isset($group['available'][$filters['category']]))
                                <span aria-hidden="true">/</span>
                                <a class="text-link" href="{{ $marketLink(['group' => $slug]) }}">{{ $group['name'] }}</a>
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
                    <p class="field-note">Showing all current listings. <a class="text-link" href="{{ $marketLink() }}">Browse categories instead</a></p>
                @endif
            </div>
        @endif
        <div @class(['browse-workspace' => !$isDiscovery])>
        <details @class(['search-disclosure', 'browse-sidebar' => !$isDiscovery]) @if(array_diff_key($filters, ['group' => true, 'view' => true]) || $errors->any()) open @endif>
            <summary>
                <x-icon name="search" size="18"/>
                <span>Search Marketplace @if($activeFilterCount) <small>({{ $activeFilterCount }} active {{ \Illuminate\Support\Str::plural('filter', $activeFilterCount) }})</small> @endif</span>
                <x-icon class="disclosure-arrow" name="chevron-down" size="17"/>
            </summary>
            <form class="panel filter-bar" method="get" action="/marketplace" x-data="{ detailCategory: @js($filters['category'] ?? '') }">
                <label>Search<input type="search" name="q" maxlength="100" value="{{ $filters['q'] ?? '' }}" placeholder="Search listings"></label>
                <label>Category<select name="category" x-model="detailCategory"><option value="">All categories</option>
                    @foreach($categoryGroups as $group)
                        <optgroup label="{{ $group['name'] }}">
                            @foreach($group['available'] as $key => $label)<option value="{{ $key }}" @selected(($filters['category'] ?? '') === $key)>{{ $label }}</option>@endforeach
                        </optgroup>
                    @endforeach
                </select></label>
                @if($locations->isNotEmpty())<label>Location<select name="location"><option value="">All Manitoba</option>@foreach($locations as $place)<option value="{{ $place->slug }}" @selected(($filters['location'] ?? '') === $place->slug)>{{ $place->name }}</option>@endforeach</select></label>@endif
                <x-marketplace-detail-filters :filters="$filters"/>
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
                    @if($filters)<a class="text-link" href="{{ $marketLink(['view' => 'all']) }}">Clear filters</a>@endif
                </div>
            </form>
        </details>
        <div class="browse-results-column">
        @unless($isDiscovery)
            @auth
                @if(auth()->user()->hasVerifiedEmail() && \App\Support\MarketplaceSearches::enabled(request()->attributes->get('site')))
                    <details class="panel mb-4"><summary>Save this Marketplace search</summary>
                        <form method="post" action="/account/marketplace-searches" class="form-stack mt-2">@csrf
                            @foreach($filters as $field => $value)<input type="hidden" name="{{ $field }}" value="{{ $value }}">@endforeach
                            <label>Search name<input name="name" required maxlength="80" placeholder="My Marketplace search"></label>
                            <button class="button button-primary">Save search</button>
                        </form>
                        <a class="text-link" href="/account/marketplace-searches">Manage saved searches</a>
                    </details>
                @endif
            @endauth
            <section class="market-results" aria-labelledby="market-results-title">
                <div class="market-results-heading">
                    <div>
                        <p class="section-label">Marketplace listings</p>
                        <h2 id="market-results-title">
                            {{ isset($filters['category']) ? $categories[$filters['category']] : ($selectedGroup ? $categoryGroups[$selectedGroup]['name'] : 'All current listings') }}
                        </h2>
                    </div>
                    <span class="discussion-count">{{ $items->total() }} {{ \Illuminate\Support\Str::plural('result', $items->total()) }}</span>
                </div>
                @if($items->isEmpty())
                    <div class="market-results-empty">
                        <span class="empty-icon" aria-hidden="true"><x-icon name="search" size="23"/></span>
                        <h3>{{ $activeFilterCount ? 'No listings match your search' : 'No listings posted yet' }}</h3>
                        <p>{{ $activeFilterCount ? 'Try fewer filters or a broader search.' : 'Nothing listed here yet. Be the first to post something.' }}</p>
                        <div class="market-empty-actions">
                            @if(array_diff_key($filters, ['location' => true, 'view' => true, 'group' => true]))
                                <a class="button button-outline" href="{{ $marketLink(['view' => 'all']) }}">Clear search filters</a>
                            @endif
                            @if($marketPlace)
                                <a class="button button-outline" href="{{ '/marketplace?'.http_build_query(array_diff_key($filters, ['location' => true]) + ['view' => 'all', 'location' => '']) }}">Search all Manitoba</a>
                            @endif
                            <a class="button button-outline" href="{{ $marketLink() }}">Browse categories</a>
                            <a class="button button-primary" href="{{ $postUrl }}"><x-icon name="plus" size="16"/> Post a listing</a>
                        </div>
                    </div>
                @else
                    <div class="listing-grid">
                        @foreach($items as $item)
                            <x-listing-card :item="$item"/>
                        @endforeach
                    </div>
                    {{ $items->links() }}
                @endif
            </section>
        @endunless
        </div>
        </div>
    </div>
</x-layouts.site>
