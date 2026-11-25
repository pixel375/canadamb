<x-layouts.site title="Private property drafts">
    <div class="container page-shell">
        <div class="page-intro"><div><a class="text-link" href="/account/profile">My account</a><h1>Private property drafts</h1><p>Prepare and manage your property information for internal review.</p></div><a class="button button-primary" href="/account/properties/new"><x-icon name="plus" size="17"/> New property draft</a></div>
        <div class="property-private-notice"><strong>Private workspace</strong><p>Drafts, submitted properties and reviewed properties are not public advertisements. Review does not authorize publication.</p></div>
        <nav class="seller-status-grid property-owner-status" aria-label="Filter property drafts">
            @foreach(array_merge(['all'], $statuses) as $status)
                <?php $params = array_filter(array_diff_key($filters, ['status' => true]) + ['status' => $status === 'all' ? null : $status]); ?>
                <a class="seller-status-tile" href="/account/properties{{ $params ? '?'.http_build_query($params) : '' }}" @if(($filters['status'] ?? 'all') === $status) aria-current="page" @endif><strong>{{ $status === 'all' ? $counts->sum() : ($counts[$status] ?? 0) }}</strong><span>{{ ucfirst(str_replace('_', ' ', $status)) }}</span></a>
            @endforeach
        </nav>
        <form method="get" action="/account/properties" class="panel filter-bar">
            @if($filters['status'] ?? null)<input type="hidden" name="status" value="{{ $filters['status'] }}">@endif
            <label>Search drafts<input type="search" name="q" maxlength="100" value="{{ $filters['q'] ?? '' }}" placeholder="Title or description"></label>
            <label>Transaction<select name="transaction"><option value="">All transactions</option><option value="sale" @selected(($filters['transaction'] ?? '') === 'sale')>For sale</option><option value="rent" @selected(($filters['transaction'] ?? '') === 'rent')>For rent</option></select></label>
            <label>Property type<select name="property_type"><option value="">All property types</option>@foreach(\App\Models\PropertyListing::TYPES as $id => $label)<option value="{{ $id }}" @selected(($filters['property_type'] ?? null) === $id)>{{ $label }}</option>@endforeach</select></label>
            <div class="inline-actions"><button class="button button-primary">Filter drafts</button><a class="text-link" href="/account/properties">Reset</a></div>
        </form>
        <p class="field-note seller-results-count">{{ $items->total() }} {{ \Illuminate\Support\Str::plural('draft', $items->total()) }} found</p>
        <section class="seller-listing-grid">
            @forelse($items as $item)
                <article class="panel seller-listing-card">
                    <div class="seller-listing-top"><div class="seller-listing-thumbnail">@if($photo = $item->photos->first())<img src="/account/properties/{{ $item->id }}/media/{{ $photo->id }}" alt="" loading="lazy">@else<x-icon name="house" size="28"/>@endif</div><div class="member-listing-info"><span class="member-listing-status" data-status="{{ $item->status }}">{{ ucfirst(str_replace('_', ' ', $item->status)) }}</span><h2>{{ $item->title }}</h2><p>{{ $item->location->name }} · {{ \App\Models\PropertyListing::TYPES[$item->property_type] }}</p><p>CAD {{ number_format($item->price_cents / 100, 2) }}{{ $item->transaction === 'rent' ? ' / month' : '' }}</p></div></div>
                    <p class="seller-lifecycle-note">{{ match($item->status) { 'draft' => 'Not submitted. Edit and complete the authority declaration when ready.', 'submitted' => 'Waiting for private review.', 'changes_requested' => 'The reviewer has requested changes.', 'reviewed' => 'Private review completed. Not authorized for publication.', default => 'Private property record.' } }}</p>
                    <div class="inline-actions seller-listing-actions"><a class="button button-primary" href="/account/properties/{{ $item->id }}">Open draft</a>@if(in_array($item->status, ['draft','changes_requested'], true))<a class="button button-outline" href="/account/properties/{{ $item->id }}/edit">Edit draft</a>@endif</div>
                </article>
            @empty<div class="panel market-results-empty"><x-icon name="house" size="30"/><h2>No draft property listings match these filters.</h2><a class="button button-outline" href="/account/properties">View all drafts</a></div>@endforelse
        </section>
        {{ $items->links() }}
    </div>
</x-layouts.site>
