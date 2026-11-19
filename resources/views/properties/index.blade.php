<x-layouts.site title="Private property drafts">
    <div class="container page-shell narrow">
        <div class="page-intro"><div><h1>Private property drafts</h1><p>Prepare property information for internal review.</p></div><a class="button button-primary" href="/account/properties/new">New property draft</a></div>
        <form method="get" action="/account/properties" class="panel filter-bar">
            <label>Title<input type="search" name="q" maxlength="100" value="{{ $filters['q'] ?? '' }}"></label>
            <label>Transaction<select name="transaction"><option value="">All transactions</option><option value="sale" @selected(($filters['transaction'] ?? '') === 'sale')>For sale</option><option value="rent" @selected(($filters['transaction'] ?? '') === 'rent')>For rent</option></select></label>
            <label>Property type<select name="property_type"><option value="">All property types</option>@foreach(\App\Models\PropertyListing::TYPES as $id => $label)<option value="{{ $id }}" @selected(($filters['property_type'] ?? null) === $id)>{{ $label }}</option>@endforeach</select></label>
            <button class="button button-primary">Filter drafts</button>
        </form>
        <section class="panel">
            @forelse($items as $item)
                <article class="item-row"><div><h2><a href="/account/properties/{{ $item->id }}">{{ $item->title }}</a></h2><p>{{ $item->location->name }} · {{ str_replace('_', ' ', ucfirst($item->status)) }}</p></div></article>
            @empty<p>No draft property listings match these filters.</p>@endforelse
            {{ $items->links() }}
        </section>
    </div>
</x-layouts.site>
