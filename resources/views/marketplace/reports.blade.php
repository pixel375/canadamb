<x-layouts.site title="My listing reports">
    <div class="container page-shell message-workspace">
        <div class="page-intro"><div><a class="text-link" href="/account/settings">Account settings</a><h1>My listing reports</h1><p>Review the reports you submitted for Marketplace listings on this portal.</p></div></div>
        <section class="panel">
            @forelse($items as $item)
                <article class="admin-review-card form-stack">
                    <div class="inline-actions"><span class="member-listing-status">{{ $item->resolved_at ? 'Review completed' : 'Awaiting review' }}</span><small class="field-note">{{ \Illuminate\Support\Carbon::parse($item->created_at)->timezone('America/Winnipeg')->format('M j, Y') }}</small></div>
                    <h2>{{ $marketEnabled && $item->public_title ? $item->public_title : 'Listing #'.$item->listing_id.' (not currently public)' }}</h2>
                    <p class="prose-text">{{ $item->reason }}</p>
                    @if($marketEnabled && $item->public_title)<a class="text-link" href="/marketplace/{{ $item->listing_id }}">View listing</a>@endif
                    <p class="field-note">{{ $item->resolved_at ? 'A moderator has completed this review. Individual moderation decisions remain private.' : 'Your report is in the moderation queue.' }}</p>
                </article>
            @empty<p>No listing reports submitted on this portal.</p>@endforelse
        </section>
        {{ $items->links() }}
    </div>
</x-layouts.site>
