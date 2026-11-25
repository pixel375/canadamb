<x-layouts.admin title="Private property review">
    <div class="page-intro"><div><h1>Private property review</h1><p>Verify advertising authority, seller role, property information and photo privacy before recording a private review. Review does not publish a property.</p></div></div>
    <x-admin-queue-filters path="/admin/properties" :filters="$filters" :counts="$counts" :statuses="['submitted','reviewed']" search-label="Search property title or description"/>
    <section class="panel">
        @forelse($items as $item)
            <article class="item-row"><div><h2>{{ $item->title }}</h2><p>{{ $item->location->name }} · {{ \App\Models\PropertyListing::TYPES[$item->property_type] }} · CAD {{ number_format($item->price_cents / 100, 2) }}</p><span class="member-listing-status" data-status="{{ $item->status }}">{{ ucfirst($item->status) }}</span></div><a class="button button-outline" href="/admin/properties/{{ $item->id }}">Open private draft</a></article>
        @empty<p>No property drafts have been submitted.</p>@endforelse
        {{ $items->links() }}
    </section>
</x-layouts.admin>
