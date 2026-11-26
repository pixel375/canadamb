<x-layouts.admin title="Marketplace moderation">
    <div class="page-intro"><div><h1>Marketplace moderation</h1><p>Review new listings and remove approved listings when needed. Your own listings and reports involving your account require an independent reviewer.</p></div></div>
    <x-admin-queue-filters path="/admin/moderation" :filters="$filters" :counts="$counts" :statuses="['pending','active','rejected','expired','sold','removed']" :places="$places" search-label="Search listing title or description"/>
    <section class="panel mb-4" id="reports">
        <h2>Listing report queue</h2>
        <form method="get" action="/admin/moderation#reports" class="form-grid">
            @foreach(['status', 'q', 'location'] as $field)
                @if(filled($filters[$field] ?? null))<input type="hidden" name="{{ $field }}" value="{{ $filters[$field] }}">@endif
            @endforeach
            <label>Report status<select name="report_status"><option value="pending" @selected(($filters['report_status'] ?? 'pending') === 'pending')>Open listing reports</option><option value="resolved" @selected(($filters['report_status'] ?? '') === 'resolved')>Resolved listing reports</option></select></label>
            <label>Report reference<input type="number" name="reference" min="1" value="{{ $filters['reference'] ?? '' }}"></label>
            <button class="button button-outline">Filter reports</button>
        </form>
        @forelse($reports as $report)
                <div class="item-row">
                    <div><h3>{{ $report->title }}</h3><p class="field-note">Report #{{ $report->id }}</p><p>{{ $report->reason }}</p></div>
                    @if(!$report->resolved_at)
                    <form method="post" action="/admin/moderation/reports/{{ $report->id }}" class="form-grid">@csrf
                        <label>Resolution note<input name="note" required maxlength="240"></label>
                        <button class="button button-outline">Resolve report</button>
                    </form>
                    @else
                        <p class="field-note">Review completed</p>
                    @endif
                </div>
            @empty
                <p>No listing reports match these filters.</p>
            @endforelse
            {{ $reports->links() }}
    </section>
    @if($items->contains(fn ($item) => in_array($item->status, ['pending', 'active'], true)))
        <details class="panel form-stack mb-4">
            <summary>Review selected listings</summary>
            <form method="post" action="/admin/moderation/selected" id="listing-batch-review" class="form-stack mt-3">
                @csrf
                <p class="field-note">Select up to ten listings on this page after individually reviewing their content and photos. All selected listings must be eligible for the same action. Nothing is changed if any selected listing is stale or ineligible.</p>
                <label>Review action<select name="status"><option value="active">Approve pending listings</option><option value="rejected">Reject pending listings</option><option value="removed">Remove active listings</option></select></label>
                <label>Shared reason (required for rejection or removal)<input name="reason" maxlength="240"></label>
                <label class="checkbox"><input name="confirm_review" type="checkbox" value="1" required> I have independently reviewed every selected listing</label>
                <div><button class="button button-outline">Apply selected moderation action</button></div>
            </form>
        </details>
    @endif
    @forelse($items as $item)
        <article class="panel form-stack admin-review-card">
            <div><span class="member-listing-status" data-status="{{ $item->status }}">{{ ucfirst($item->status) }}</span><h2>{{ $item->title }}</h2><p>{{ $item->seller?->name ?? 'Former member' }} · {{ $item->location->name }} · {{ $item->categoryRecord->name }} · {{ $item->priceLabel() }} · {{ ucfirst($item->status) }}</p></div>
                        @if($item->status === 'active' && $item->expires_at?->isFuture())<a class="text-link" href="/marketplace/{{ $item->id }}">Open public listing</a>@endif
            @if($item->media->isNotEmpty())
                <div class="listing-photo-preview">@foreach($item->media as $photo)<figure><a href="/marketplace/{{ $item->id }}/photo/{{ $photo->id }}" aria-label="Open photo {{ $loop->iteration }} for review"><img src="/marketplace/{{ $item->id }}/thumbnail/{{ $photo->id }}" alt="Listing photo {{ $loop->iteration }}" loading="lazy"></a><figcaption>{{ $loop->first ? 'Cover photo' : 'Photo '.$loop->iteration }}</figcaption></figure>@endforeach</div>
            @endif
            <p class="prose-text">{{ $item->description }}</p>
            @if(in_array($item->status, ['pending', 'active'], true))
            <label class="checkbox"><input form="listing-batch-review" type="checkbox" name="listings[]" value="{{ $item->id }}"> Select this listing for review</label>
            <input form="listing-batch-review" type="hidden" name="versions[{{ $item->id }}]" value="{{ $item->reviewVersion() }}">
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
            @else
                <p class="field-note">Historical record · no moderation actions available.</p>
            @endif
        </article>
    @empty<div class="panel"><p>There are no listings awaiting review.</p></div>@endforelse
    {{ $items->links() }}
</x-layouts.admin>
