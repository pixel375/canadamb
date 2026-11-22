<x-layouts.site title="My listings">
    @php
        $site = request()->attributes->get('site');
    @endphp
    <div class="container page-shell">
        <div class="page-intro"><div><h1>My listings</h1><p>Manage your Marketplace posts.</p></div><div class="inline-actions">
            @if($marketplaceEnabled)
                <a class="button button-primary" href="/marketplace/new">Post a listing</a>
                <a class="button button-outline" href="/account/saved">Saved listings</a>
            @endif
            <a class="button button-outline" href="/account/messages">Messages</a>
            <a class="button button-outline" href="/account/notifications">Notifications</a>
            @if(app(\App\Support\Community::class)->enabled($site, 'events'))<a class="button button-outline" href="/account/events">My events</a>@endif
            @if(app(\App\Support\JobBoard::class)->enabled($site))<a class="button button-outline" href="/account/saved-jobs">Saved jobs</a>@endif
            @if(config('network.property_drafts'))<a class="button button-outline" href="/account/properties">Private property drafts</a>@endif
            <a class="button button-outline" href="/account/businesses">My businesses</a>
            @can('manage-network')<a class="button button-outline" href="{{ \App\Support\NetworkUrl::to(\App\Support\NetworkUrl::main(), '/admin') }}">Network admin</a>@endcan
            @can('manage-news')<a class="button button-outline" href="{{ \App\Support\NetworkUrl::to(\App\Support\NetworkUrl::main(), '/admin/news') }}">News editorial</a>@endcan
            <form method="post" action="/account/logout">@csrf<button class="button button-outline">Sign out everywhere</button></form>
        </div></div>
        @if($marketplaceEnabled)
            <div class="panel">
                @forelse($items as $item)
                    @php
                        $status = $item->seller_status;
                        $statusLabel = $status === 'pending' ? 'Pending review' : ucfirst($status);
                        $expiry = $item->expires_at?->timezone('America/Winnipeg');
                    @endphp
                    <article class="item-row">
                        <div>
                            <h2>
                                @if($status === 'active')
                                    <a class="text-link" href="/marketplace/{{ $item->id }}">{{ $item->title }}</a>
                                @else
                                    {{ $item->title }}
                                @endif
                            </h2>
                            <p>{{ $item->location->name }} · <strong>{{ $statusLabel }}</strong> · {{ $item->priceLabel() }}</p>
                            @if($expiry && in_array($status, ['active', 'expired'], true))
                                <p class="field-note">{{ $status === 'active' ? 'Expires' : 'Expired' }} <time datetime="{{ $expiry->toIso8601String() }}">{{ $expiry->format('M j, Y \a\t g:i a T') }}</time></p>
                            @endif
                            @if($item->moderation_reason)
                                <p><strong>Moderator’s reason</strong> {{ $item->moderation_reason }}</p>
                            @endif
                            @if($status === 'removed')<p class="field-note">Kept in your listing history.</p>@endif
                        </div>
                        @if($status !== 'removed')
                            <div class="inline-actions">
                                @if(in_array($status, ['pending', 'active', 'expired', 'rejected'], true))
                                    <a class="button button-outline" href="/account/listings/{{ $item->id }}/edit">Edit</a>
                                @endif
                                <form method="post" action="/account/listings/{{ $item->id }}/status" class="inline-actions">
                                    @csrf
                                    @if($status === 'active')<button class="button button-outline" name="status" value="sold" type="submit">Mark sold</button>@endif
                                    @if(in_array($status, ['expired', 'sold'], true))<button class="button button-outline" name="status" value="pending" type="submit">Relist for review</button>@endif
                                    <details>
                                        <summary>Remove listing</summary>
                                        <p class="field-note">Hide this listing and keep it in your history.</p>
                                        <button class="button button-outline" name="status" value="removed" type="submit">Hide listing</button>
                                    </details>
                                </form>
                            </div>
                        @endif
                    </article>
                @empty
                    <p>You have no listings on this portal.</p>
                @endforelse
            </div>
            {{ $items->links() }}
        @else
            <div class="panel"><p>Marketplace is currently unavailable on this portal.</p></div>
        @endif
    </div>
</x-layouts.site>
