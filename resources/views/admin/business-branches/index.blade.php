<x-layouts.admin title="Business location reviews">
    <div class="page-intro"><div><h1>Location changes awaiting review</h1><p>Verify physical addresses and closures independently. Preserve source records, past job IDs and historical conversations.</p></div></div>
    <section class="panel">
        @forelse($requests as $change)
            <article class="item-row"><div><h2>{{ $change->name }}</h2><p>{{ ucfirst(str_replace('_', ' ', $change->action)) }} · {{ $change->created_at }}</p></div>
                <a class="button button-outline" href="/admin/business-branches/{{ $change->id }}">Review privately</a>
            </article>
        @empty <p>No location requests waiting for review.</p> @endforelse
        {{ $requests->links() }}
    </section>
</x-layouts.admin>
