<x-layouts.site title="My listing reports">
    <div class="container page-shell message-workspace">
        <div class="page-intro"><div><a class="text-link" href="/account/settings">Account settings</a><h1>My listing reports</h1><p>Review the reports you submitted for Marketplace listings on this portal.</p></div></div>
        <form method="get" action="/account/listing-reports" class="panel form-stack mb-4">
            <p>{{ $pending }} awaiting review · {{ $resolved }} completed</p>
            <div class="form-grid"><label>Status<select name="status"><option value="">All statuses</option><option value="pending" @selected(($filters['status'] ?? '') === 'pending')>Awaiting review</option><option value="resolved" @selected(($filters['status'] ?? '') === 'resolved')>Review completed</option></select></label><label>Report reference<input name="reference" type="number" min="1" value="{{ $filters['reference'] ?? '' }}"></label></div>
            <div class="inline-actions"><button class="button button-primary">Filter reports</button><a class="button button-outline" href="/account/listing-reports">Reset</a></div>
        </form>
        <section class="panel">
            @forelse($items as $item)
                <article class="admin-review-card form-stack">
                    <div class="inline-actions"><span class="member-listing-status">{{ $item->resolved_at ? 'Review completed' : 'Awaiting review' }}</span><small class="field-note">{{ \Illuminate\Support\Carbon::parse($item->created_at)->timezone('America/Winnipeg')->format('M j, Y') }}</small></div>
                    <p class="field-note">Report #{{ $item->id }}</p>
                    <h2>{{ $marketEnabled && $item->public_title ? $item->public_title : 'Listing #'.$item->listing_id.' (not currently public)' }}</h2>
                    <p class="prose-text">{{ $item->reason }}</p>
                    @if($marketEnabled && $item->public_title)<a class="text-link" href="/marketplace/{{ $item->listing_id }}">View listing</a>@endif
                    <p class="field-note">{{ $item->resolved_at ? 'A moderator has completed this review. Individual moderation decisions remain private.' : 'Your report is in the moderation queue.' }}</p>
                </article>
            @empty
                @if(array_filter($filters))
                    <p>No listing reports match these filters.</p>
                @else
                    <p>No listing reports submitted on this portal.</p>
                @endif
            @endforelse
        </section>
        {{ $items->links() }}
    </div>
</x-layouts.site>
