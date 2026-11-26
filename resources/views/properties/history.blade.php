<x-layouts.site title="Archived property history">
    <div class="container page-shell">
        <div class="page-intro"><div><a class="text-link" href="/account/properties">Private property drafts</a><h1>Archived property history</h1><p>Read-only summaries of previously archived drafts. Photos, private addresses and descriptions are not displayed. Archived drafts cannot be restored here.</p></div></div>
        <form method="get" action="/account/properties/history" class="panel saved-search">
            <label>Title<input type="search" name="q" maxlength="100" value="{{ $filters['q'] ?? '' }}"></label>
            <label>Reference<input type="number" name="reference" min="1" value="{{ $filters['reference'] ?? '' }}"></label>
            <button class="button button-primary">Search history</button><a class="text-link" href="/account/properties/history">Reset</a>
        </form>
        <p class="field-note">{{ $items->total() }} archived records</p>
        <section class="panel form-stack">
            @forelse($items as $item)
                <article class="item-row"><div><h2>{{ $item->title }}</h2><p>{{ $item->location->name }} · {{ \App\Models\PropertyListing::TYPES[$item->property_type] }} · {{ $item->transaction === 'rent' ? 'For rent' : 'For sale' }}</p><p class="field-note">Reference #{{ $item->id }} · Archived {{ $item->updated_at->timezone('America/Winnipeg')->format('M j, Y') }}</p></div></article>
            @empty<p>No archived properties match this selection.</p>@endforelse
        </section>
        {{ $items->links() }}
    </div>
</x-layouts.site>
