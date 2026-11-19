<x-layouts.site title="Saved listings">
    <div class="container page-shell">
        <div class="page-intro"><div><h1>Saved listings</h1><p>Find active items you saved on this portal.</p></div><a class="text-link" href="/account/listings">My account</a></div>
        @if($items->isEmpty())<div class="panel"><p>You have no active saved listings here.</p></div>@endif
        <div class="listing-grid">
            @foreach($items as $item)<div><x-listing-card :item="$item"/><form method="post" action="/marketplace/{{ $item->id }}/save" class="mt-2">@csrf @method('DELETE')<button class="button button-outline">Unsave listing</button></form></div>@endforeach
        </div>
        {{ $items->links() }}
    </div>
</x-layouts.site>
