<x-layouts.admin title="Private property review">
    <div class="page-intro"><div><h1>Private property review</h1><p>Verify owner authority, seller role, listing details and media before recording a private review. This does not publish properties.</p></div></div>
    <section class="panel">
        @forelse($items as $item)
            <article class="item-row"><div><h2>{{ $item->title }}</h2><p>{{ $item->location->name }} · {{ ucfirst($item->status) }}</p></div><a class="button button-outline" href="/admin/properties/{{ $item->id }}">Open private draft</a></article>
        @empty<p>No property drafts have been submitted.</p>@endforelse
        {{ $items->links() }}
    </section>
</x-layouts.admin>
