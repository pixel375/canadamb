<x-layouts.site title="Manitoba communities">
    <div class="container page-shell">
        <div class="page-intro"><div><h1>Manitoba communities</h1><p>Find a community and explore its listings, businesses, jobs and discussions.</p></div><a class="button button-outline" href="/?location=">Browse all Manitoba</a></div>
        <section class="panel community-preference-banner" aria-label="Preferred community">
            <span class="community-preference-icon"><x-icon name="pin" size="25"/></span>
            <div><h2>{{ $preferredCommunity ? 'Your preferred community: '.$preferredCommunity->name : 'Choose your preferred community' }}</h2><p>{{ $preferredCommunity ? 'Your next visit to the provincial homepage will open this community.' : 'Remember a community on this browser, or browse any location without saving it.' }}</p></div>
            @if($preferredCommunity)
                <div class="inline-actions">
                    <a class="button button-primary" href="/?location={{ $preferredCommunity->slug }}">Open {{ $preferredCommunity->name }}</a>
                    <form method="post" action="/communities/preference">@csrf<input type="hidden" name="location" value=""><button class="button button-outline">Clear preference</button></form>
                </div>
            @endif
        </section>
        <form method="get" action="/communities" class="panel filter-bar">
            <label>Community name<input type="search" name="q" value="{{ $filters['q'] ?? '' }}" maxlength="100" placeholder="e.g. Brandon"></label>
            <label>Place type<select name="type"><option value="">All types</option>@foreach($types as $type)<option value="{{ $type }}" @selected(($filters['type'] ?? '') === $type)>{{ ucfirst($type) }}</option>@endforeach</select></label>
            <div class="inline-actions"><button class="button button-primary"><x-icon name="search" size="16"/> Find a community</button><a class="text-link" href="/communities">Reset filters</a></div>
        </form>
        <p class="field-note">{{ $locations->total() }} {{ \Illuminate\Support\Str::plural('community', $locations->total()) }} found</p>
        <div class="place-grid">
            @forelse($locations as $place)
                <article class="panel community-place-card">
                    <div><h2>{{ $place->name }}</h2><p>{{ ucfirst($place->type) }}</p></div>
                    @if($preferredCommunity?->id === $place->id)<span class="community-preferred-label"><x-icon name="check" size="16"/> Preferred</span>@endif
                    <div class="community-place-actions">
                        <a class="button button-outline" href="/?location={{ $place->slug }}">Browse on CanadaMB <x-icon name="arrow" size="16"/></a>
                        @if($place->site?->status === 'active')
                            <a class="text-link" href="{{ \App\Support\NetworkUrl::to($place->site) }}">Visit {{ $place->site->name }} <x-icon name="arrow" size="16"/></a>
                        @endif
                        @if($preferredCommunity?->id !== $place->id)
                            <form method="post" action="/communities/preference">@csrf<input type="hidden" name="location" value="{{ $place->slug }}"><button class="text-link" type="submit"><x-icon name="pin" size="16"/> Remember {{ $place->name }}</button></form>
                        @endif
                    </div>
                </article>
            @empty
                <div class="panel market-results-empty"><h2>No matching locations.</h2><p>Try a different community name or place type.</p><a class="button button-outline" href="/communities">Clear filters</a></div>
            @endforelse
        </div>
        {{ $locations->links() }}
    </div>
</x-layouts.site>
