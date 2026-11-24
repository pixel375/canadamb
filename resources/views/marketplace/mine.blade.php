<x-layouts.site title="My listings">
    <div class="container page-shell">
        <div class="page-intro">
            <div><a class="text-link" href="/account/profile">My account</a><h1>My listings</h1><p>Manage your Marketplace posts, publication status and renewals.</p></div>
            <div class="inline-actions"><a class="button button-outline" href="/account/saved">Saved listings</a><a class="button button-primary" href="/marketplace/new"><x-icon name="plus" size="18"/> Post a listing</a></div>
        </div>
        <nav class="seller-status-grid" aria-label="Filter listings by status">
            @foreach(array_merge(['all'], $statuses) as $status)
                <?php $selected = ($filters['status'] ?? 'all') === $status; ?>
                <a class="seller-status-tile" href="/account/listings{{ ($query = http_build_query(array_filter(['status' => $status === 'all' ? null : $status, 'q' => $filters['q'] ?? null]))) ? '?'.$query : '' }}" @if($selected) aria-current="page" @endif>
                    <strong>{{ $status === 'all' ? $counts->sum() : ($counts[$status] ?? 0) }}</strong>
                    <span>{{ $status === 'all' ? 'All listings' : ucfirst($status) }}</span>
                </a>
            @endforeach
        </nav>
        <form method="get" action="/account/listings" class="panel seller-listing-search">
            @if($filters['status'] ?? null)<input type="hidden" name="status" value="{{ $filters['status'] }}">@endif
            <label for="seller-listing-q" class="sr-only">Search my listings</label>
            <input id="seller-listing-q" type="search" name="q" maxlength="100" value="{{ $filters['q'] ?? '' }}" placeholder="Search your listings">
            <button class="button button-primary"><x-icon name="search" size="16"/> Search</button>
            @if($filters['q'] ?? null)<a class="text-link" href="/account/listings{{ isset($filters['status']) ? '?status='.$filters['status'] : '' }}">Clear</a>@endif
        </form>
        <p class="field-note seller-results-count">{{ $items->total() }} {{ \Illuminate\Support\Str::plural('listing', $items->total()) }} found</p>
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
                            <p>{{ $item->location->name }} · {{ $item->priceLabel() }}</p>
                        </div>
                    </div>
                    <p class="seller-lifecycle-note">
                        @switch($item->status)
                            @case('active')
                                @if($item->expires_at?->isFuture()) Visible to buyers · Expires {{ $item->expires_at->format('M j, Y') }}
                                @else Publication period ended. Awaiting automatic expiry. @endif
                                @break
                            @case('pending') Awaiting moderator approval. Your listing is not public yet. @break
                            @case('expired') No longer public. Renew to submit for review again. @break
                            @case('rejected') Not approved. Edit the listing before submitting it again. @break
                            @case('sold') Marked sold. No longer visible in Marketplace. @break
                        @endswitch
                    </p>
                    <div class="inline-actions seller-listing-actions">
                        @if($item->status === 'active' && $item->expires_at?->isFuture())
                            <a class="button button-outline" href="/marketplace/{{ $item->id }}">View listing</a>
                        @endif
                        @if(in_array($item->status, ['pending','active','expired','rejected'], true))
                            <a class="button button-outline" href="/account/listings/{{ $item->id }}/edit">Edit</a>
                        @endif
                        @if($item->status === 'active' || $item->status === 'expired')
                            <form method="post" action="/account/listings/{{ $item->id }}/status">@csrf
                                @if($item->status === 'active')<button class="button button-outline" name="status" value="sold">Mark sold</button>@else<button class="button button-primary" name="status" value="pending">Renew</button>@endif
                            </form>
                        @endif
                    </div>
                    <details class="seller-remove-listing">
                        <summary>Remove listing</summary>
                        <p class="field-note">This will permanently remove this listing from your dashboard and public Marketplace. It cannot be restored here.</p>
                        <form method="post" action="/account/listings/{{ $item->id }}/status">@csrf<button class="button button-outline" name="status" value="removed">Confirm removal</button></form>
                    </details>
                </article>
            @empty
                <div class="panel market-results-empty"><span class="empty-icon"><x-icon name="marketplace" size="24"/></span>
                    @if(($filters['q'] ?? null) || ($filters['status'] ?? null))
                        <h3>No listings match these filters.</h3><a class="button button-outline" href="/account/listings">View all my listings</a>
                    @else
                        <h3>You have no listings on this portal.</h3><p>Post your first item to reach local buyers.</p><a class="button button-primary" href="/marketplace/new">Post a listing</a>
                    @endif
                </div>
            @endforelse
        </div>
        {{ $items->links() }}
    </div>
</x-layouts.site>
