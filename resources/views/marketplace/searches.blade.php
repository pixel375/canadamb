<x-layouts.site title="Saved Marketplace searches">
    <div class="container page-shell">
        <div class="page-intro"><div><a class="text-link" href="/account/saved">Saved listings</a><h1>Saved Marketplace searches</h1><p>Keep your favorite filters on this portal. Result counts reflect currently public listings.</p></div><a class="button button-primary" href="/marketplace?view=all">Browse Marketplace</a></div>
        <x-saved-navigation current="marketplace-searches" :total="$items->count()"/>
        <section class="seller-listing-grid">@forelse($items as $item)
            <article class="panel seller-listing-card form-stack"><h2>{{ $item->name }}</h2><p>{{ $counts[$item->id] }} {{ \Illuminate\Support\Str::plural('matching listing', $counts[$item->id]) }}</p>
                <x-saved-filter-summary :filters="$item->filters" kind="marketplace" :categories="$categories" :groups="$groups" :places="$places"/>
                <a class="button button-primary" href="/marketplace?{{ http_build_query($item->filters + ['view' => 'all'] + (request()->attributes->get('site')->isNetwork() ? ['location' => ''] : [])) }}">Run search</a>
                <a class="button button-outline" href="/account/marketplace-searches/{{ $item->id }}/edit">Edit filters</a>
                <details><summary>Rename search</summary><form method="post" class="form-stack mt-2" action="/account/marketplace-searches/{{ $item->id }}/rename">@csrf<input type="hidden" name="version" value="{{ $item->editVersion() }}"><label>Name<input name="name" required maxlength="80" value="{{ $item->name }}"></label><button class="button button-outline">Save name</button></form></details>
                <details><summary>Remove search</summary><form method="post" class="mt-2" action="/account/marketplace-searches/{{ $item->id }}">@csrf<input type="hidden" name="version" value="{{ $item->editVersion() }}"> @method('DELETE')<button class="button button-outline">Confirm removal</button></form></details>
            </article>
        @empty<div class="panel"><h2>No saved searches on this portal.</h2><p>Browse Marketplace results and select Save this Marketplace search.</p></div>@endforelse</section>
        <p class="field-note">15 searches across the network. These are reusable filters, not email or push alerts.</p>
    </div>
</x-layouts.site>
