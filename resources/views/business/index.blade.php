<x-layouts.site title="Business directory">
    <div class="container page-shell browse-page">
        <div class="page-intro"><div><h1>Business directory</h1><p>Find businesses and services in {{ request()->attributes->get('site')->location?->name ?? $places->firstWhere('slug', $filters['location'] ?? null)?->name ?? 'Manitoba' }}.</p></div><a class="button button-primary" href="/account/businesses/new"><x-icon name="plus" size="16"/> Add your business</a></div>
        <div class="browse-workspace">
        <details class="search-disclosure browse-sidebar" @if(!empty($filters)) open @endif>
            <summary><x-icon name="search" size="18"/> Find businesses <x-icon class="disclosure-arrow" name="chevron-down" size="17"/></summary>
            <form action="/businesses" method="get" class="panel filter-bar">
            <label>Business or service<input type="search" name="q" placeholder="Search names and services" value="{{ $filters['q'] ?? '' }}" maxlength="100"></label>
            <label>Category<select name="category"><option value="">All categories</option>@foreach($categories as $key => $category)<option value="{{ $key }}" @selected(($filters['category'] ?? '') === $key)>{{ $category }}</option>@endforeach</select></label>
            @if($places->isNotEmpty())<label>Location<select name="location"><option value="">All Manitoba</option>@foreach($places as $place)<option value="{{ $place->slug }}" @selected(($filters['location'] ?? '') === $place->slug)>{{ $place->name }}</option>@endforeach</select></label>@endif
            <button class="button button-primary"><x-icon name="search" size="16"/> Find businesses</button>
        </form>
        </details>
        <div class="browse-results-column">
        @php
            $activeFilters = array_filter($filters, fn ($value) => $value !== null && $value !== '');
            $selectedPlace = $places->firstWhere('slug', $activeFilters['location'] ?? null);
            $scope = $selectedPlace ? ['location' => $selectedPlace->slug] : [];
            $clearUrl = '/businesses'.($scope ? '?'.http_build_query($scope) : '');
            $wideFilters = array_diff_key($activeFilters, ['location' => true]);
            $wideUrl = '/businesses'.($wideFilters ? '?'.http_build_query($wideFilters) : '');
        @endphp
        <section class="panel business-category-discovery" aria-labelledby="business-category-heading">
            <div class="section-heading"><h2 id="business-category-heading">Browse by category</h2><a class="text-link" href="{{ $clearUrl }}">View all businesses</a></div>
            <?php
                $categoryIcons = ['retail' => 'shopping-bag', 'food' => 'building', 'services' => 'briefcase', 'trades' => 'settings', 'health' => 'shield', 'agriculture' => 'tractor', 'arts' => 'calendar', 'other' => 'layers'];
                $categoryParams = array_diff_key($activeFilters, ['category' => true]);
            ?>
            <nav class="business-category-grid" aria-label="Business categories">
                @foreach($categories as $key => $category)
                    <a class="business-category-tile" href="/businesses?{{ http_build_query($categoryParams + ['category' => $key]) }}" @if(($activeFilters['category'] ?? null) === $key) aria-current="page" @endif>
                        <span class="business-category-icon"><x-icon :name="$categoryIcons[$key] ?? 'building'" size="23"/></span>
                        <span><strong>{{ $category }}</strong><small>{{ $categoryCounts[$key] ?? 0 }} {{ \Illuminate\Support\Str::plural('business', $categoryCounts[$key] ?? 0) }}</small></span>
                    </a>
                @endforeach
            </nav>
        </section>
        <section class="market-results" aria-labelledby="business-results-title">
            <div class="market-results-heading">
                <div>
                    <p class="section-label">Business directory</p>
                    <h2 id="business-results-title">{{ $categories[$activeFilters['category'] ?? ''] ?? 'Businesses' }} in {{ $selectedPlace?->name ?? request()->attributes->get('site')->location?->name ?? 'Manitoba' }}</h2>
                </div>
                <span class="discussion-count">{{ $businesses->total() }} {{ \Illuminate\Support\Str::plural('result', $businesses->total()) }}</span>
            </div>
            @if($businesses->isEmpty())
                <div class="market-results-empty">
                    <span class="empty-icon" aria-hidden="true"><x-icon name="building" size="23"/></span>
                    <h3>{{ $activeFilters ? 'No matching businesses.' : 'Discover local businesses' }}</h3>
                    <p>{{ $activeFilters ? 'Try a different business name, category, or a wider location.' : 'There are no published businesses here yet. Help neighbours discover your business.' }}</p>
                    <div class="market-empty-actions">
                        @if(array_diff_key($activeFilters, ['location' => true]))
                            <a class="button button-outline" href="{{ $clearUrl }}">Clear search filters</a>
                        @endif
                        @if($selectedPlace)
                            <a class="button button-outline" href="{{ $wideUrl }}">Search all Manitoba</a>
                        @endif
                        <a class="button button-primary" href="/account/businesses/new"><x-icon name="plus" size="16"/> Add your business</a>
                    </div>
                </div>
            @else
                <div class="place-grid">
                    @foreach($businesses as $business)
                        <article class="panel business-result-card">
                            <span class="location-icon mb-2"><x-icon :name="$categoryIcons[$business->category] ?? 'building'"/></span>
                            <h3><a href="/businesses/{{ $business->slug }}">{{ $business->name }}</a></h3>
                            <p>{{ $categories[$business->category] }} · {{ $selectedPlace?->name ?? request()->attributes->get('site')->location?->name ?? $business->locations->pluck('location.name')->unique()->join(', ') }}</p>
                            @if(filled($business->description))
                                <div class="business-result-excerpt">{{ \Illuminate\Support\Str::limit(\Illuminate\Support\Str::squish(strip_tags($business->description)), 140) }}</div>
                            @endif
                            @if($business->phone || $business->website || $business->public_email)<p class="business-contact-summary"><x-icon name="check" size="14"/> Contact details available</p>@endif
                            <a class="text-link" href="/businesses/{{ $business->slug }}">View profile <x-icon name="arrow" size="16"/></a>
                        </article>
                    @endforeach
                </div>
                {{ $businesses->links() }}
            @endif
        </section>
        </div>
        </div>
    </div>
</x-layouts.site>
