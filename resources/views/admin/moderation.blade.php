<x-layouts.admin title="Marketplace moderation">
    <div class="page-intro"><div><h1>Marketplace moderation</h1><p>Review new listings and remove approved listings when needed.</p></div></div>
    @if($reports->isNotEmpty())
        <div class="panel">
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
    @endif
    @forelse($items as $item)
        <article class="panel form-stack">
            <div><h2>{{ $item->title }}</h2><p>{{ $item->seller->name }} · {{ $item->location->name }} · {{ $item->categoryRecord->name }} · {{ $item->priceLabel() }} · {{ ucfirst($item->status) }}</p></div>
            <p class="prose-text">{{ $item->description }}</p>
            @if($item->media->isNotEmpty())
                <ul class="listing-photo-previews" aria-label="Submitted photos">
                    @foreach($item->media as $photo)
                        <li>
                            <a class="listing-gallery-link" href="/marketplace/{{ $item->id }}/photo/{{ $photo->id }}" target="_blank" rel="noopener">
                                <img src="/marketplace/{{ $item->id }}/thumbnail/{{ $photo->id }}" alt="Submitted photo {{ $loop->iteration }} (opens in a new tab)" loading="lazy">
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
            <form action="/admin/moderation/{{ $item->id }}" method="post" class="form-stack">
                @csrf
                <input type="hidden" name="version" value="{{ \App\Support\ListingPhotoOrder::version($item) }}">
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
