<x-layouts.site title="My listings">
    <div class="container page-shell">
        <div class="page-intro"><div><h1>My listings</h1><p>Manage your Marketplace posts.</p></div><div class="inline-actions">
            <a class="button button-outline" href="/account/saved">Saved listings</a>
            <a class="button button-outline" href="/account/messages">Messages</a>
            @php($site = request()->attributes->get('site'))
            <a class="button button-outline" href="/account/notifications">Notifications</a>
            @if(app(\App\Support\Community::class)->enabled($site, 'events'))<a class="button button-outline" href="/account/events">My events</a>@endif
            @if(app(\App\Support\JobBoard::class)->enabled($site))<a class="button button-outline" href="/account/saved-jobs">Saved jobs</a>@endif
            @if(config('network.property_drafts'))<a class="button button-outline" href="/account/properties">Private property drafts</a>@endif
            <a class="button button-outline" href="/account/businesses">My businesses</a>
            @can('manage-network')<a class="button button-outline" href="{{ \App\Support\NetworkUrl::to(\App\Support\NetworkUrl::main(), '/admin') }}">Network admin</a>@endcan
            @can('manage-news')<a class="button button-outline" href="{{ \App\Support\NetworkUrl::to(\App\Support\NetworkUrl::main(), '/admin/news') }}">News editorial</a>@endcan
            <form method="post" action="/account/logout">@csrf<button class="button button-outline">Sign out everywhere</button></form>
        </div></div>
        <div class="panel">
            @forelse($items as $item)
                <article class="item-row">
                    <div><h3>{{ $item->title }}</h3><p>{{ $item->location->name }} · {{ ucfirst($item->status) }} · {{ $item->priceLabel() }}</p></div>
                    <div class="inline-actions">
                        @if(in_array($item->status, ['pending','active','expired','rejected'], true))<a class="button button-outline" href="/account/listings/{{ $item->id }}/edit">Edit</a>@endif
                        <form method="post" action="/account/listings/{{ $item->id }}/status" class="inline-actions">@csrf
                            @if($item->status === 'active')<button class="button button-outline" name="status" value="sold">Mark sold</button>@endif
                            @if($item->status === 'expired')<button class="button button-outline" name="status" value="pending">Renew</button>@endif
                            <button class="button button-outline" name="status" value="removed">Remove</button>
                        </form>
                    </div>
                </article>
            @empty<p>You have no listings on this portal.</p>@endforelse
        </div>
        {{ $items->links() }}
    </div>
</x-layouts.site>
