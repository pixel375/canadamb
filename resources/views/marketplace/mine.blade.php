<x-layouts.site title="My listings">
    @php($returnFilters = array_filter($filters, fn ($value) => $value !== null && $value !== ''))
    @php($returnQuery = $returnFilters ? '?'.http_build_query(['return_filters' => $returnFilters], '', '&', PHP_QUERY_RFC3986) : '')
    @php($canSell = $items->contains(fn ($item) => $item->allowsSellerStatus('sold')))
    @php($canRenew = $items->contains(fn ($item) => $item->allowsSellerStatus('pending')))
    <div class="container page-shell">
        <div class="page-intro">
            <div><a class="text-link" href="/account/profile">My account</a><h1>My listings</h1><p>Manage your Marketplace posts, publication status and renewals.</p></div>
            <div class="inline-actions"><a class="button button-outline" href="/account/saved">Saved listings</a><a class="button button-primary" href="/marketplace/new{{ $returnQuery }}"><x-icon name="plus" size="18"/> Post a listing</a></div>
        </div>
        <nav class="seller-status-grid" aria-label="Filter listings by status">
            @foreach(array_merge(['all'], $statuses) as $status)
                <?php $selected = ($filters['status'] ?? 'all') === $status; ?>
                <a class="seller-status-tile" href="/account/listings{{ ($query = http_build_query(array_filter(array_replace($filters, ['status' => $status === 'all' ? null : $status]), fn ($value) => $value !== null && $value !== ''))) ? '?'.$query : '' }}" @if($selected) aria-current="page" @endif>
                    <strong>{{ $status === 'all' ? $counts->sum() : ($counts[$status] ?? 0) }}</strong>
                    <span>{{ $status === 'all' ? 'All listings' : ucfirst($status) }}</span>
                </a>
            @endforeach
        </nav>
        <form method="get" action="/account/listings" class="panel form-stack">
            @if($filters['status'] ?? null)<input type="hidden" name="status" value="{{ $filters['status'] }}">@endif
            <div class="form-grid">
                <label>Search my listings<input id="seller-listing-q" type="search" name="q" maxlength="100" value="{{ $filters['q'] ?? '' }}" placeholder="Search your listings"></label>
                @if($places->isNotEmpty())<label>Community<select name="location"><option value="">All my communities</option>@foreach($places as $place)<option value="{{ $place->id }}" @selected((int) ($filters['location'] ?? 0) === $place->id)>{{ $place->name }}</option>@endforeach</select></label>@endif
                <label>Category<select name="category"><option value="">All categories</option>@foreach($categories as $key => $name)<option value="{{ $key }}" @selected(($filters['category'] ?? '') === $key)>{{ $name }}</option>@endforeach</select></label>
                <label>Publication period<select name="expiry"><option value="">Any publication period</option><option value="soon" @selected(($filters['expiry'] ?? '') === 'soon')>Expires within 7 days</option><option value="ended" @selected(($filters['expiry'] ?? '') === 'ended')>Publication ended</option></select></label>
                <label>Listing reference<input type="number" name="reference" min="1" value="{{ $filters['reference'] ?? '' }}"></label>
                <label>Sort by<select name="sort">@foreach(['newest' => 'Newest first', 'oldest' => 'Oldest first', 'price-low' => 'Lowest price', 'price-high' => 'Highest price', 'expiry' => 'Earliest expiry'] as $key => $label)<option value="{{ $key }}" @selected(($filters['sort'] ?? 'newest') === $key)>{{ $label }}</option>@endforeach</select></label>
            </div>
            <div class="inline-actions"><button class="button button-primary"><x-icon name="search" size="16"/> Search</button><a class="text-link" href="/account/listings">Clear filters</a></div>
        </form>
        <p class="field-note seller-results-count">{{ $items->total() }} {{ \Illuminate\Support\Str::plural('listing', $items->total()) }} found</p>
        @if($canSell || $canRenew)
            <details class="panel form-stack mb-4"><summary>Manage selected listings</summary>
                <form method="post" id="listing-batch-status" action="/account/listings/batch-status" class="form-stack mt-3">@csrf
                    @foreach($returnFilters as $key => $value)<input type="hidden" name="return_filters[{{ $key }}]" value="{{ $value }}">@endforeach
                    <p class="field-note">Select up to ten listings below. All must be eligible for the same action; renewal returns them to moderation for another 7 days.</p>
                    <label>Action<select name="status">@if($canSell)<option value="sold">Mark active listings sold</option>@endif @if($canRenew)<option value="pending">Renew expired listings for 7 days</option>@endif</select></label>
                    <label class="checkbox-line"><input type="checkbox" name="confirm_batch" value="1" required> Confirm changes to selected listings</label>
                    <button class="button button-outline">Apply to selected listings</button>
                </form>
            </details>
        @endif
        <div class="member-listing-manager seller-listing-grid">
            @forelse($items as $item)
                <article class="panel seller-listing-card">
                    <div class="seller-listing-top">
                        <div class="seller-listing-thumbnail">
                            @if($photo = $item->media->first())
                                <img src="/marketplace/{{ $item->id }}/thumbnail/{{ $photo->id }}" alt="" loading="lazy">
                            @else
                                <x-icon name="photo" size="25"/>
                            @endif
                        </div>
                        <div class="member-listing-info">
                            <span class="member-listing-status" data-status="{{ $item->status }}">{{ ucfirst($item->status) }}</span>
                            <h3>{{ $item->title }}</h3>
                            <p>{{ $item->location->name }} · {{ $item->priceLabel() }}<br><small>{{ $item->categoryRecord?->name }} · #{{ $item->id }}</small></p>
                        </div>
                    </div>
                    <p class="seller-lifecycle-note">
                        @switch($item->status)
                            @case('active')
                                @if($item->expires_at?->isFuture()) Visible to buyers · Expires {{ $item->expires_at->format('M j, Y') }}
                                @else Publication period ended. Renew for 7 days after review. @endif
                                @break
                            @case('pending') Awaiting moderator approval. Your listing is not public yet. @break
                            @case('expired') No longer public. Renew for 7 days after review. @break
                            @case('rejected') Not approved. Edit the listing before submitting it again. @break
                            @case('sold') Marked sold. No longer visible in Marketplace. @break
                        @endswitch
                    </p>
                    <div class="inline-actions seller-listing-actions">
                        <a class="text-link" href="/account/listings/{{ $item->id }}/history{{ $returnQuery }}">Status history</a>
                        @if(in_array($item->status, ['sold','expired','rejected'], true))<a class="button button-outline" href="/account/listings/{{ $item->id }}/duplicate{{ $returnQuery }}">Create similar listing</a>@endif
                        @if($item->status === 'active' && $item->expires_at?->isFuture())
                            <a class="button button-outline" href="/marketplace/{{ $item->id }}">View listing</a>
                        @endif
                        @if(in_array($item->status, ['pending','active','expired','rejected'], true))
                            <a class="button button-outline" href="/account/listings/{{ $item->id }}/edit{{ $returnQuery }}">Edit</a>
                        @endif
                        @if($item->allowsSellerStatus('sold') || $item->allowsSellerStatus('pending'))
                            <form method="post" action="/account/listings/{{ $item->id }}/status">@csrf @foreach($returnFilters as $key => $value)<input type="hidden" name="return_filters[{{ $key }}]" value="{{ $value }}">@endforeach<input type="hidden" name="version" value="{{ $item->reviewVersion() }}">
                                @if($item->allowsSellerStatus('sold'))<button class="button button-outline" name="status" value="sold">Mark sold</button>@else<button class="button button-primary" name="status" value="pending">Renew for 7 days</button>@endif
                            </form>
                        @endif
                    </div>
                    @if($item->allowsSellerStatus('sold') || $item->allowsSellerStatus('pending'))
                        <label class="checkbox-line"><input form="listing-batch-status" type="checkbox" name="listings[]" value="{{ $item->id }}"> {{ $item->allowsSellerStatus('sold') ? 'Select to mark sold' : 'Select for renewal' }}</label>
                        <input form="listing-batch-status" type="hidden" name="versions[{{ $item->id }}]" value="{{ $item->reviewVersion() }}">
                    @endif
                    <details class="seller-remove-listing">
                        <summary>Remove listing</summary>
                        <p class="field-note">This will permanently remove this listing from your dashboard and public Marketplace. It cannot be restored here.</p>
                        <form method="post" action="/account/listings/{{ $item->id }}/status">@csrf @foreach($returnFilters as $key => $value)<input type="hidden" name="return_filters[{{ $key }}]" value="{{ $value }}">@endforeach<input type="hidden" name="version" value="{{ $item->reviewVersion() }}"><button class="button button-outline" name="status" value="removed">Confirm removal</button></form>
                    </details>
                </article>
            @empty
                <div class="panel market-results-empty"><span class="empty-icon"><x-icon name="marketplace" size="24"/></span>
                    @if(array_filter($filters, fn ($value) => $value !== null && $value !== ''))
                        <h3>No listings match these filters.</h3><a class="button button-outline" href="/account/listings">View all my listings</a>
                    @else
                        <h3>You have no listings on this portal.</h3><p>Post your first item to reach local buyers.</p><a class="button button-primary" href="/marketplace/new{{ $returnQuery }}">Post a listing</a>
                    @endif
                </div>
            @endforelse
        </div>
        {{ $items->links() }}
    </div>
</x-layouts.site>
