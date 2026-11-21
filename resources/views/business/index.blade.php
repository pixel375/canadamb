<x-layouts.site title="Business directory">
    <div class="container page-shell">
        <div class="page-intro"><div><h1>Business directory</h1><p>Find businesses and services in {{ request()->attributes->get('site')->location?->name ?? 'Manitoba' }}.</p></div><a class="button button-primary" href="/account/businesses/new"><x-icon name="plus" size="16"/> Add your business</a></div>
        <details class="search-disclosure" @if(!empty($filters)) open @endif>
            <summary><x-icon name="search" size="18"/> Find businesses <x-icon class="disclosure-arrow" name="chevron-down" size="17"/></summary>
            <form action="/businesses" method="get" class="panel filter-bar">
            <label>Business name<input type="search" name="q" value="{{ $filters['q'] ?? '' }}" maxlength="100"></label>
            <label>Category<select name="category"><option value="">All categories</option>@foreach($categories as $key => $category)<option value="{{ $key }}" @selected(($filters['category'] ?? '') === $key)>{{ $category }}</option>@endforeach</select></label>
            @if($places->isNotEmpty())<label>Location<select name="location"><option value="">All Manitoba</option>@foreach($places as $place)<option value="{{ $place->slug }}" @selected(($filters['location'] ?? '') === $place->slug)>{{ $place->name }}</option>@endforeach</select></label>@endif
            <button class="button button-primary"><x-icon name="search" size="16"/> Find businesses</button>
        </form>
        </details>
        <div class="place-grid">
            @forelse($businesses as $business)
                @php($location = request()->attributes->get('site')->location)
                <article class="panel">
                    <span class="location-icon mb-2"><x-icon name="building"/></span>
                    <h2>{{ $business->name }}</h2>
                    <p>{{ $categories[$business->category] }} · {{ $location?->name ?? $business->locations->pluck('location.name')->unique()->join(', ') }}</p>
                    <a class="text-link" href="/businesses/{{ $business->slug }}">View profile <x-icon name="arrow" size="16"/></a>
                </article>
            @empty<div class="panel"><p>No matching businesses.</p></div>@endforelse
        </div>
        {{ $businesses->links() }}
    </div>
</x-layouts.site>
