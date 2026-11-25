<x-layouts.site title="My events">
    <div class="container page-shell">
        <div class="page-intro"><div><a class="text-link" href="/account/profile">My account</a><h1>My events</h1><p>Review your event submissions and publication status.</p></div><a class="button button-primary" href="/events/new"><x-icon name="plus" size="17"/> Submit an event</a></div>
        <nav class="seller-status-grid" aria-label="Event status">
            @foreach(array_merge(['all'], $statuses) as $status)
                <?php $params = array_filter(['status' => $status === 'all' ? null : $status, 'q' => $filters['q'] ?? null]); ?>
                <a class="seller-status-tile" href="/account/events{{ $params ? '?'.http_build_query($params) : '' }}" @if(($filters['status'] ?? 'all') === $status) aria-current="page" @endif><strong>{{ $status === 'all' ? $counts->sum() : ($counts[$status] ?? 0) }}</strong><span>{{ ucfirst($status) }}</span></a>
            @endforeach
        </nav>
        <form class="panel seller-listing-search" method="get" action="/account/events">
            @if($filters['status'] ?? null)<input type="hidden" name="status" value="{{ $filters['status'] }}">@endif
            <label for="owned-event-search" class="sr-only">Search your events</label><input type="search" id="owned-event-search" name="q" maxlength="100" value="{{ $filters['q'] ?? '' }}" placeholder="Search your events"><button class="button button-primary">Search</button>
        </form>
        <p class="field-note seller-results-count">{{ $items->total() }} {{ \Illuminate\Support\Str::plural('event', $items->total()) }} found</p>
        <details class="panel form-stack mb-4"><summary>Cancel selected events</summary><form method="post" id="event-batch-cancel" action="/account/events/cancel-selected" class="form-stack mt-2">@csrf
            <p class="field-note">Choose up to eight upcoming events using the checkboxes below. Pending submissions are withdrawn; approved events are marked cancelled. Existing discussions remain intact.</p>
            <label>Private cancellation note<input name="cancellation_note" maxlength="300"></label>
            <label class="checkbox-line"><input type="checkbox" name="confirm_cancel" value="1" required> Confirm cancellation of the selected events</label><button class="button button-outline">Cancel selected events</button>
        </form></details>
        <section class="seller-listing-grid">
            @forelse($items as $item)
                <article class="panel seller-listing-card">
                    <div class="event-discovery-card"><div class="event-date-tile"><strong>{{ $item->localStart()->format('j') }}</strong><span>{{ $item->localStart()->format('M') }}</span></div><div><span class="member-listing-status" data-status="{{ $item->status }}">{{ ucfirst($item->status) }}</span><h2>{{ $item->title }}</h2><p>{{ $item->localStart()->format('M j, Y · g:i a') }} · {{ $item->location->name }}</p><p>{{ $item->venue }}</p></div></div>
                    <p class="seller-lifecycle-note">{{ match($item->status) { 'pending' => 'Waiting for moderator approval.', 'approved' => 'Approved for the community calendar.', 'rejected' => 'Not approved for publication.', 'cancelled' => 'Public event marked cancelled.', 'withdrawn' => 'Submission withdrawn before publication.', default => ucfirst($item->status) } }}</p>
                    @if($item->cancellation_note)<p class="field-note">Private cancellation note · {{ $item->cancellation_note }}</p>@endif
                    @if(in_array($item->status, ['approved','cancelled'], true))<a class="button button-outline" href="/events/{{ $item->id }}">View event</a>@endif
                    @if(in_array($item->status, ['pending', 'approved', 'rejected'], true) && !$item->localStart()->isPast())
                        @if(!in_array($item->id, $linked, true))<a class="button button-outline" href="/account/events/{{ $item->id }}/edit">Edit event</a>
                        @else<p class="field-note">Linked discussion: cancel and reuse the event if details need correcting.</p>@endif
                    @endif
                    <a class="text-link" href="/events/new?reuse={{ $item->id }}">Reuse event details</a>
                    @if(in_array($item->status, ['pending', 'approved'], true))
                        @if(!($item->localEnd() ?? $item->localStart())->isPast())<label class="checkbox-line"><input form="event-batch-cancel" type="checkbox" name="events[]" value="{{ $item->id }}"> Select for batch cancellation</label>@endif
                        <details class="seller-remove-listing"><summary>{{ $item->status === 'pending' ? 'Withdraw submission' : 'Cancel event' }}</summary>
                            <form method="post" action="/account/events/{{ $item->id }}/cancel" class="form-stack mt-3">@csrf<input type="hidden" name="version" value="{{ $item->contentVersion() }}">
                                <label>Private cancellation note<input name="cancellation_note" maxlength="300"></label><button class="button button-outline">{{ $item->status === 'pending' ? 'Confirm withdrawal' : 'Confirm cancellation' }}</button>
                            </form>
                        </details>
                    @endif
                </article>
            @empty<div class="panel market-results-empty"><h2>{{ ($filters['q'] ?? null) || ($filters['status'] ?? null) ? 'No events match these filters.' : 'No events submitted yet.' }}</h2><a class="button button-outline" href="/account/events">View all my events</a></div>@endforelse
        </section>
        {{ $items->links() }}
    </div>
</x-layouts.site>
