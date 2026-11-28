<x-layouts.site title="Listing status history">
    <div class="container page-shell listing-editor">
        <div class="page-intro"><div><a class="text-link" href="{{ $returnUrl }}">My listings</a><h1>Status history</h1><p>{{ $item->title }} · {{ $item->location->name }}</p></div></div>
        <section class="panel form-stack"><h2>Current status · {{ ucfirst($item->status) }}</h2><p class="field-note">Your listing's recorded status transitions. Internal review notes and staff identities are not included.</p>
            @forelse($history as $event)
                <div class="item-row"><div><strong>{{ $event->previous_status ? ucfirst($event->previous_status).' → ' : '' }}{{ ucfirst($event->status) }}</strong><p>{{ \Illuminate\Support\Carbon::parse($event->created_at)->setTimezone('America/Winnipeg')->format('M j, Y g:i A') }} Manitoba time</p></div></div>
            @empty<p>No recorded status transitions.</p>@endforelse
            {{ $history->links() }}
        </section>
    </div>
</x-layouts.site>
