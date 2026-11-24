<x-layouts.site title="My listings">
    <div class="container page-shell">
        <div class="page-intro"><div><a class="text-link" href="/account/profile">My account</a><h1>My listings</h1><p>Manage your Marketplace posts.</p></div><a class="button button-primary" href="/marketplace/new"><x-icon name="plus" size="18"/> Post a listing</a></div>
        <div class="panel member-listing-manager">
            @forelse($items as $item)
                <article class="item-row">
                    <div class="member-listing-info"><span class="member-listing-status" data-status="{{ $item->status }}">{{ ucfirst($item->status) }}</span><h3>{{ $item->title }}</h3><p>{{ $item->location->name }} · {{ $item->priceLabel() }}</p></div>
                    <div class="inline-actions">
                        @if(in_array($item->status, ['pending','active','expired','rejected'], true))<a class="button button-outline" href="/account/listings/{{ $item->id }}/edit">Edit</a>@endif
                        <form method="post" action="/account/listings/{{ $item->id }}/status" class="inline-actions">@csrf
                            @if($item->status === 'active')<button class="button button-outline" name="status" value="sold">Mark sold</button>@endif
                            @if($item->status === 'expired')<button class="button button-outline" name="status" value="pending">Renew</button>@endif
                            <button class="button button-outline" name="status" value="removed">Remove</button>
                        </form>
                    </div>
                </article>
            @empty<div class="market-results-empty"><span class="empty-icon"><x-icon name="marketplace" size="24"/></span><h3>You have no listings on this portal.</h3><p>Post your first item to reach local buyers.</p><a class="button button-primary" href="/marketplace/new">Post a listing</a></div>@endforelse
        </div>
        {{ $items->links() }}
    </div>
</x-layouts.site>
