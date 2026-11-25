<x-layouts.site title="Manitoba jobs">
    <div class="container page-shell browse-page">
        <div class="page-intro"><div><h1>Jobs</h1><p>Browse active job postings in {{ request()->attributes->get('site')->location?->name ?? 'Manitoba' }}.</p></div>
            @auth <a href="/account/saved-jobs" class="button button-outline">Saved jobs</a> @endauth
        </div>
        <div class="browse-workspace">
        <details class="search-disclosure browse-sidebar" @if(!empty($filters)) open @endif>
            <summary><x-icon name="search" size="18"/> Search jobs <x-icon class="disclosure-arrow" name="chevron-down" size="17"/></summary>
            <form method="get" action="/jobs" class="panel filter-bar">
            <label>Search jobs<input type="search" name="q" maxlength="100" value="{{ $filters['q'] ?? '' }}"></label>
            @if(request()->attributes->get('site')->isNetwork())<label>Location<input type="search" name="location" maxlength="150" value="{{ $filters['location'] ?? '' }}"></label>@endif
            <label>Employment type<select name="type"><option value="">All types</option>@foreach(\App\Support\JobBoard::TYPES as $id => $label)<option value="{{ $id }}" @selected(($filters['type'] ?? null) === $id)>{{ $label }}</option>@endforeach</select></label>
            <label>Workplace<select name="workplace"><option value="">Any workplace</option>@foreach(\App\Support\JobBoard::WORKPLACES as $id => $label)<option value="{{ $id }}" @selected(($filters['workplace'] ?? null) === $id)>{{ $label }}</option>@endforeach</select></label>
            <label>Pay period<select name="pay_unit"><option value="">Any pay period</option><option value="hour" @selected(($filters['pay_unit'] ?? null) === 'hour')>Hourly</option><option value="year" @selected(($filters['pay_unit'] ?? null) === 'year')>Annual</option></select></label>
            <label>Minimum pay (CAD)<input name="minimum_pay" type="number" min="0" step="0.01" value="{{ $filters['minimum_pay'] ?? '' }}"></label>
            <button type="submit" class="button button-primary">Filter jobs</button>
        </form>
        </details>
        <div class="browse-results-column">
        @php
            $activeFilters = array_filter($filters, fn ($value) => $value !== null && $value !== '');
            $localSite = request()->attributes->get('site')->location;
            $scope = !$localSite && filled($activeFilters['location'] ?? null) ? ['location' => $activeFilters['location']] : [];
            $clearUrl = '/jobs'.($scope ? '?'.http_build_query($scope) : '');
            $wideFilters = array_diff_key($activeFilters, ['location' => true]);
            $wideUrl = '/jobs'.($wideFilters ? '?'.http_build_query($wideFilters) : '');
        @endphp
        @auth
            @if(auth()->user()->hasVerifiedEmail() && \App\Support\JobSearches::enabled(request()->attributes->get('site')))
                <details class="panel mb-4"><summary>Save this job search</summary>
                    <form class="form-stack mt-2" method="post" action="/account/job-searches">@csrf
                        @foreach($activeFilters as $field => $value)<input type="hidden" name="{{ $field }}" value="{{ $value }}">@endforeach
                        <label>Search name<input name="name" required maxlength="80" placeholder="My job search"></label>
                        <label class="checkbox"><input type="checkbox" name="alerts" value="1" checked> Show new matching jobs in my account</label>
                        <button class="button button-primary">Save search</button>
                    </form>
                    <a class="text-link" href="/account/job-searches">Manage saved searches</a>
                </details>
            @endif
        @endauth
        <section class="market-results" aria-labelledby="jobs-results-title">
            <div class="market-results-heading">
                <div>
                    <p class="section-label">Employment opportunities</p>
                    <h2 id="jobs-results-title">Jobs in {{ $localSite?->name ?? ($scope['location'] ?? 'Manitoba') }}</h2>
                </div>
                <span class="discussion-count">{{ $items->total() }} {{ \Illuminate\Support\Str::plural('result', $items->total()) }}</span>
            </div>
            @if($items->isEmpty())
                <div class="market-results-empty">
                    <span class="empty-icon" aria-hidden="true"><x-icon name="search" size="23"/></span>
                    <h3>{{ $activeFilters ? 'No active jobs match these filters.' : 'No active jobs posted yet' }}</h3>
                    <p>{{ $activeFilters ? 'Try another keyword or fewer filters to find more opportunities.' : 'Check back for new opportunities from Manitoba employers.' }}</p>
                    <div class="market-empty-actions">
                        @if(array_diff_key($activeFilters, ['location' => true]))
                            <a class="button button-outline" href="{{ $clearUrl }}">Clear search filters</a>
                        @endif
                        @if($scope)
                            <a class="button button-outline" href="{{ $wideUrl }}">Search all Manitoba</a>
                        @endif
                        @if(app(\App\Support\BusinessDirectory::class)->enabled(request()->attributes->get('site')))
                            <a class="button button-primary" href="/businesses">Explore local businesses</a>
                        @endif
                    </div>
                </div>
            @else
                <div class="browse-feed">
                    @foreach($items as $item)
                        <div class="panel"><x-job-result :item="$item" :show-description="true"/></div>
                    @endforeach
                </div>
                {{ $items->links() }}
            @endif
        </section>
        </div>
        </div>
    </div>
</x-layouts.site>
