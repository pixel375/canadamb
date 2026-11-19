<x-layouts.site title="Manitoba communities">
    <div class="container page-shell">
        <div class="page-intro"><div><h1>Manitoba communities</h1><p>Find a community and explore its local listings.</p></div></div>
        <form method="get" action="/communities" class="panel filter-bar">
            <label>Community name<input type="search" name="q" value="{{ $filters['q'] ?? '' }}" maxlength="100" placeholder="e.g. Brandon"></label>
            <label>Place type<select name="type"><option value="">All types</option>@foreach($types as $type)<option value="{{ $type }}" @selected(($filters['type'] ?? '') === $type)>{{ ucfirst($type) }}</option>@endforeach</select></label>
            <button class="button button-primary"><x-icon name="search" size="16"/> Find a community</button>
        </form>
        <div class="place-grid">
            @forelse($locations as $place)
                <article class="panel"><h2>{{ $place->name }}</h2><p>{{ $place->type }}</p>
                    @if($place->site?->status === 'active')
                        <a class="text-link" href="{{ \App\Support\NetworkUrl::to($place->site) }}">Visit {{ $place->site->name }} <x-icon name="arrow" size="16"/></a>
                    @else<a class="text-link" href="/?location={{ $place->slug }}">Browse this community <x-icon name="arrow" size="16"/></a>@endif
                </article>
            @empty<p>No matching locations.</p>@endforelse
        </div>
        {{ $locations->links() }}
    </div>
</x-layouts.site>
