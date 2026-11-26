<x-layouts.site title="Private property drafts">
    <div class="container page-shell">
        <div class="page-intro"><div><a class="text-link" href="/account/profile">My account</a><h1>Private property drafts</h1><p>Prepare and manage your property information for internal review.</p></div><a class="button button-primary" href="/account/properties/new"><x-icon name="plus" size="17"/> New property draft</a></div>
        <div class="property-private-notice"><strong>Private workspace</strong><p>Drafts, submitted properties and reviewed properties are not public advertisements. Review does not authorize publication.</p></div>
        <nav class="seller-status-grid property-owner-status" aria-label="Filter property drafts">
            @foreach(array_merge(['all'], $statuses) as $status)
                <?php $params = array_filter(array_diff_key($filters, ['status' => true]) + ['status' => $status === 'all' ? null : $status], fn ($value) => $value !== null && $value !== ''); ?>
                <a class="seller-status-tile" href="/account/properties{{ $params ? '?'.http_build_query($params) : '' }}" @if(($filters['status'] ?? 'all') === $status) aria-current="page" @endif><strong>{{ $status === 'all' ? $counts->sum() : ($counts[$status] ?? 0) }}</strong><span>{{ ucfirst(str_replace('_', ' ', $status)) }}</span></a>
            @endforeach
        </nav>
        <form method="get" action="/account/properties" class="panel filter-bar">
            @if($filters['status'] ?? null)<input type="hidden" name="status" value="{{ $filters['status'] }}">@endif
            <label>Search drafts<input type="search" name="q" maxlength="100" value="{{ $filters['q'] ?? '' }}" placeholder="Title or description"></label>
            <label>Transaction<select name="transaction"><option value="">All transactions</option><option value="sale" @selected(($filters['transaction'] ?? '') === 'sale')>For sale</option><option value="rent" @selected(($filters['transaction'] ?? '') === 'rent')>For rent</option></select></label>
            <label>Property type<select name="property_type"><option value="">All property types</option>@foreach(\App\Models\PropertyListing::TYPES as $id => $label)<option value="{{ $id }}" @selected(($filters['property_type'] ?? null) === $id)>{{ $label }}</option>@endforeach</select></label>
            <label>Sort<select name="sort"><option value="recent" @selected(($filters['sort'] ?? 'recent') === 'recent')>Recently updated</option><option value="price-low" @selected(($filters['sort'] ?? '') === 'price-low')>Price low to high</option><option value="price-high" @selected(($filters['sort'] ?? '') === 'price-high')>Price high to low</option></select></label>
            <div class="inline-actions"><button class="button button-primary">Filter drafts</button><a class="text-link" href="/account/properties">Reset</a></div>
        </form>
        <p class="field-note seller-results-count">{{ $items->total() }} {{ \Illuminate\Support\Str::plural('draft', $items->total()) }} found</p>
        @if($items->contains(fn ($item) => in_array($item->status, ['submitted', 'reviewed'], true)))
            <details class="panel form-stack mb-4"><summary>Return selected properties to draft</summary>
                <form id="property-batch-withdraw" method="post" action="/account/properties/withdraw-selected" class="form-stack mt-3">@csrf
                    <p class="field-note">Select up to ten submitted or reviewed properties. Their private review status is reset; photos and property details are preserved.</p>
                    <label class="checkbox-line"><input type="checkbox" name="confirm_withdraw" value="1" required> Confirm returning selected properties to draft</label>
                    <button class="button button-outline">Return selected properties</button>
                </form>
            </details>
        @endif
        @if($items->contains(fn ($item) => in_array($item->status, ['draft', 'changes_requested'], true)))
            <details class="panel form-stack mb-4"><summary>Submit selected properties for review</summary>
                <form id="property-batch-submit" method="post" action="/account/properties/submit-selected" class="form-stack mt-3">@csrf
                    <p class="field-note">Choose up to ten prepared drafts below. Only select properties for which you have the same advertising authority. Review remains private and does not authorize publication.</p>
                    <label>Your capacity for every selected property<select name="authorization_capacity" required><option value="">Select your authority</option><option value="owner">I own every selected property</option><option value="authorized-agent">I have each owner's authorization</option></select></label>
                    <label class="checkbox-line"><input type="checkbox" name="advertising_consent" value="1" required> I confirm I have authority to submit every selected property for private review, and understand that public advertising requires separate consent verification and approval.</label>
                    <button class="button button-primary">Submit selected for private review</button>
                </form>
            </details>
        @endif
        <section class="seller-listing-grid">
            @forelse($items as $item)
                <article class="panel seller-listing-card">
                    <div class="seller-listing-top"><div class="seller-listing-thumbnail">@if($photo = $item->photos->first())<img src="/account/properties/{{ $item->id }}/media/{{ $photo->id }}" alt="" loading="lazy">@else<x-icon name="house" size="28"/>@endif</div><div class="member-listing-info"><span class="member-listing-status" data-status="{{ $item->status }}">{{ ucfirst(str_replace('_', ' ', $item->status)) }}</span><h2>{{ $item->title }}</h2><p>{{ $item->location->name }} · {{ \App\Models\PropertyListing::TYPES[$item->property_type] }}</p><p>CAD {{ number_format($item->price_cents / 100, 2) }}{{ $item->transaction === 'rent' ? ' / month' : '' }}</p></div></div>
                    <p class="seller-lifecycle-note">{{ match($item->status) { 'draft' => 'Not submitted. Edit and complete the authority declaration when ready.', 'submitted' => 'Waiting for private review.', 'changes_requested' => 'The reviewer has requested changes.', 'reviewed' => 'Private review completed. Not authorized for publication.', default => 'Private property record.' } }}</p>
                    @if(in_array($item->status, ['submitted', 'reviewed'], true))
                        <label class="checkbox-line"><input form="property-batch-withdraw" type="checkbox" name="properties[]" value="{{ $item->id }}"> Select to return to draft</label>
                        <input form="property-batch-withdraw" type="hidden" name="versions[{{ $item->id }}]" value="{{ $item->contentVersion() }}">
                    @endif
                    @if(in_array($item->status, ['draft', 'changes_requested'], true))
                        <label class="checkbox-line"><input form="property-batch-submit" type="checkbox" name="properties[]" value="{{ $item->id }}"> Select for private review</label>
                        <input form="property-batch-submit" type="hidden" name="versions[{{ $item->id }}]" value="{{ $item->contentVersion() }}">
                    @endif
                    <div class="inline-actions seller-listing-actions"><a class="button button-primary" href="/account/properties/{{ $item->id }}">Open draft</a><a class="text-link" href="/account/properties/{{ $item->id }}/duplicate">Create similar draft</a>@if(in_array($item->status, ['draft','changes_requested'], true))<a class="button button-outline" href="/account/properties/{{ $item->id }}/edit">Edit draft</a>@endif</div>
                </article>
            @empty<div class="panel market-results-empty"><x-icon name="house" size="30"/><h2>No draft property listings match these filters.</h2><a class="button button-outline" href="/account/properties">View all drafts</a></div>@endforelse
        </section>
        {{ $items->links() }}
    </div>
</x-layouts.site>
