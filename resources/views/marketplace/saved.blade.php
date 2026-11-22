<x-layouts.site title="Saved listings">
    <div class="container page-shell">
        <div class="page-intro">
            <div><h1>Saved listings</h1><p>Find active items you saved that are available on this portal.</p></div>
            <div class="inline-actions">
                <a class="button button-outline" href="/account/listings">My listings</a>
                <a class="button button-primary" href="/marketplace">Browse Marketplace</a>
            </div>
        </div>
        @if($items->isEmpty())<div class="panel"><p>You have no active saved listings here.</p></div>@endif
        <div class="listing-grid">
            @foreach($items as $item)
                <div>
                    <x-listing-card :item="$item"/>
                    <form method="post" action="/marketplace/{{ $item->id }}/save" class="mt-2">
                        @csrf
                        @method('DELETE')
                        <button class="button button-outline" type="submit" aria-label="Unsave {{ $item->title }}">Unsave listing</button>
                    </form>
                </div>
            @endforeach
        </div>
        {{ $items->links() }}
    </div>
</x-layouts.site>
