<x-layouts.site title="Marketplace">
    <div class="container page-shell">
        <div class="page-intro"><div><h1>Marketplace</h1><p>Browse listings in {{ request()->attributes->get('site')->location?->name ?? 'Manitoba' }}.</p></div><a class="button button-primary" href="/marketplace/new"><x-icon name="plus" size="16"/> Post a listing</a></div>
        <form class="panel filter-bar" method="get" action="/marketplace">
            <label>Search<input type="search" name="q" maxlength="100" value="{{ $filters['q'] ?? '' }}" placeholder="Search listings"></label>
            <label>Category<select name="category"><option value="">All categories</option>@foreach($categories as $key => $label)<option value="{{ $key }}" @selected(($filters['category'] ?? '') === $key)>{{ $label }}</option>@endforeach</select></label>
            @if($locations->isNotEmpty())<label>Location<select name="location"><option value="">All Manitoba</option>@foreach($locations as $place)<option value="{{ $place->slug }}" @selected(($filters['location'] ?? '') === $place->slug)>{{ $place->name }}</option>@endforeach</select></label>@endif
            <label>Condition<select name="condition"><option value="">Any condition</option>@foreach(\App\Support\Marketplace::CONDITIONS as $key => $label)<option value="{{ $key }}" @selected(($filters['condition'] ?? '') === $key)>{{ $label }}</option>@endforeach</select></label>
            <label>Max price (CAD)<input type="number" name="max_price" value="{{ $filters['max_price'] ?? '' }}" min="0" step=".01" placeholder="Any price"></label>
            <button class="button button-primary"><x-icon name="search" size="16"/> Search</button>
        </form>
        @if($items->isEmpty())
            <div class="empty-state"><span class="empty-icon"><x-icon name="tag"/></span><div><h2>No listings found</h2><p>Try a broader search or post a listing of your own.</p></div></div>
        @else
            <div class="listing-grid">@foreach($items as $item)<x-listing-card :item="$item"/>@endforeach</div>
            {{ $items->links() }}
        @endif
    </div>
</x-layouts.site>
