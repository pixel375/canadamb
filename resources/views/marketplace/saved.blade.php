<x-layouts.site title="Saved listings">
    <div class="container page-shell">
        <div class="page-intro"><div><h1>Saved listings</h1><p>Find active items you saved on this portal.</p></div><a class="text-link" href="/account/profile">My account</a></div>
        @if($items->isEmpty())<div class="market-results-empty"><span class="empty-icon"><x-icon name="tag" size="24"/></span><h3>You have no active saved listings here.</h3><p>Save items you like to find them again later.</p><a class="button button-primary" href="/marketplace">Browse Marketplace</a></div>@endif
        <div class="listing-grid">
            @foreach($items as $item)<div><x-listing-card :item="$item"/><form method="post" action="/marketplace/{{ $item->id }}/save" class="mt-2">@csrf @method('DELETE')<button class="button button-outline">Unsave listing</button></form></div>@endforeach
        </div>
        {{ $items->links() }}
    </div>
</x-layouts.site>
