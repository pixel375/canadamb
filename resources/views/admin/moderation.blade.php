<x-layouts.admin title="Marketplace moderation">
    <div class="page-intro"><div><h1>Marketplace moderation</h1><p>Review new listings and remove approved listings when needed. Your own listings and reports involving your account require an independent reviewer.</p></div></div>
    <x-admin-queue-filters path="/admin/moderation" :filters="$filters" :counts="$counts" :statuses="['pending','active']" search-label="Search listing title or description"/>
    @if($reports->isNotEmpty())
        <div class="panel" id="reports">
            <h2>Open listing reports</h2>
            @foreach($reports as $report)
                <div class="item-row">
                    <div><h3>{{ $report->title }}</h3><p>{{ $report->reason }}</p></div>
                    <form method="post" action="/admin/moderation/reports/{{ $report->id }}" class="form-grid">@csrf
                        <label>Resolution note<input name="note" required maxlength="240"></label>
                        <button class="button button-outline">Resolve report</button>
                    </form>
                </div>
            @endforeach
            {{ $reports->links() }}
        </div>
    @else
        <section class="panel mb-4" id="reports"><h2>Open listing reports</h2><p>No unresolved listing reports.</p></section>
    @endif
    @forelse($items as $item)
        <article class="panel form-stack admin-review-card">
            <div><span class="member-listing-status" data-status="{{ $item->status }}">{{ ucfirst($item->status) }}</span><h2>{{ $item->title }}</h2><p>{{ $item->seller->name }} · {{ $item->location->name }} · {{ $item->categoryRecord->name }} · {{ $item->priceLabel() }} · {{ ucfirst($item->status) }}</p></div>
                        @if($item->status === 'active' && $item->expires_at?->isFuture())<a class="text-link" href="/marketplace/{{ $item->id }}">Open public listing</a>@endif
            @if($item->media->isNotEmpty())
                <div class="listing-photo-preview">@foreach($item->media as $photo)<figure><a href="/marketplace/{{ $item->id }}/photo/{{ $photo->id }}" aria-label="Open photo {{ $loop->iteration }} for review"><img src="/marketplace/{{ $item->id }}/thumbnail/{{ $photo->id }}" alt="Listing photo {{ $loop->iteration }}" loading="lazy"></a><figcaption>{{ $loop->first ? 'Cover photo' : 'Photo '.$loop->iteration }}</figcaption></figure>@endforeach</div>
            @endif
            <p class="prose-text">{{ $item->description }}</p>
            <form action="/admin/moderation/{{ $item->id }}" method="post" class="form-stack">
                @csrf
                <input type="hidden" name="version" value="{{ $item->reviewVersion() }}">
                <label>Reason for rejection or removal<input name="reason" maxlength="240"></label>
                <div class="inline-actions">
                    @if($item->status === 'pending')
                        <button class="button button-primary" name="status" value="active">Approve</button>
                        <button class="button button-outline" name="status" value="rejected">Reject</button>
                    @else<button class="button button-outline" name="status" value="removed">Remove from public view</button>@endif
                </div>
            </form>
        </article>
    @empty<div class="panel"><p>There are no listings awaiting review.</p></div>@endforelse
    {{ $items->links() }}
</x-layouts.admin>
