<x-layouts.site title="My events">
    @php($returnFilters = array_filter(\Illuminate\Support\Arr::except($filters, ['series']), fn ($value) => $value !== null && $value !== ''))
    @php($editParams = array_filter(['series' => $filters['series'] ?? null, 'return_filters' => $returnFilters]))
    <div class="container page-shell">
        <div class="page-intro"><div><a class="text-link" href="/account/profile">My account</a><h1>My events</h1><p>Review your event submissions and publication status.</p></div><a class="button button-primary" href="{{ app(\App\Support\Community::class)->enabled(request()->attributes->get('site'), 'community') ? '/community/new?type=event' : '/events/new' }}"><x-icon name="plus" size="17"/> Submit an event</a></div>
        <nav class="seller-status-grid" aria-label="Event status">
            @foreach(array_merge(['all'], $statuses) as $status)
                <?php $params = array_filter(array_replace($filters, ['status' => $status === 'all' ? null : $status]), fn ($value) => $value !== null && $value !== ''); ?>
                <a class="seller-status-tile" href="/account/events{{ $params ? '?'.http_build_query($params) : '' }}" @if(($filters['status'] ?? 'all') === $status) aria-current="page" @endif><strong>{{ $status === 'all' ? $counts->sum() : ($counts[$status] ?? 0) }}</strong><span>{{ ucfirst($status) }}</span></a>
            @endforeach
        </nav>
        <form class="panel saved-search" method="get" action="/account/events">
            @if($filters['series'] ?? null)<input type="hidden" name="series" value="{{ $filters['series'] }}">@endif
            @if($filters['status'] ?? null)<input type="hidden" name="status" value="{{ $filters['status'] }}">@endif
            <label for="owned-event-search">Search your events<input type="search" id="owned-event-search" name="q" maxlength="100" value="{{ $filters['q'] ?? '' }}" placeholder="Search your events"></label>
            @if(request()->attributes->get('site')->isNetwork())
                <label>Community<select name="location"><option value="">All communities</option>@foreach($places as $place)<option value="{{ $place->id }}" @selected((int) ($filters['location'] ?? 0) === $place->id)>{{ $place->name }}</option>@endforeach</select></label>
            @endif
            <label>Category<select name="category"><option value="">All categories</option>@foreach(\App\Support\Community::EVENTS as $key => $label)<option value="{{ $key }}" @selected(($filters['category'] ?? '') === $key)>{{ $label }}</option>@endforeach</select></label>
            <label>Reference<input type="number" name="reference" min="1" value="{{ $filters['reference'] ?? '' }}"></label>
            <label>Dates<select name="sort"><option value="recent" @selected(($filters['sort'] ?? 'recent') === 'recent')>All dates, latest first</option><option value="upcoming" @selected(($filters['sort'] ?? '') === 'upcoming')>Upcoming and ongoing</option><option value="past" @selected(($filters['sort'] ?? '') === 'past')>Past events</option></select></label><button class="button button-primary">Search</button>
        </form>
        @if(array_filter($filters, fn ($value) => $value !== null && $value !== ''))
            <a class="text-link" href="/account/events">Reset event filters</a>
        @endif
        @if($filters['series'] ?? null)<p class="notice">Showing one recurring series. <a class="text-link" href="/account/events">Return to all events</a></p>@endif
        <p class="field-note seller-results-count">{{ $items->total() }} {{ \Illuminate\Support\Str::plural('event', $items->total()) }} found</p>
        @if($items->contains(fn ($item) => $item->status === 'pending' || ($item->status === 'approved' && !($item->localEnd() ?? $item->localStart())->isPast())))
        <details class="panel form-stack mb-4"><summary>Cancel selected events</summary><form method="post" id="event-batch-cancel" action="/account/events/cancel-selected" class="form-stack mt-2">@csrf
                    @if($filters['series'] ?? null)<input type="hidden" name="series" value="{{ $filters['series'] }}">@endif
                    @foreach($returnFilters as $key => $value)<input type="hidden" name="return_filters[{{ $key }}]" value="{{ $value }}">@endforeach
            <p class="field-note">Choose up to eight eligible events using the checkboxes below. Pending submissions can be withdrawn; upcoming or ongoing approved events can be cancelled. Existing discussions remain intact.</p>
            <label>Private cancellation note<input name="cancellation_note" maxlength="300"></label>
            <label class="checkbox-line"><input type="checkbox" name="confirm_cancel" value="1" required> Confirm cancellation of the selected events</label><button class="button button-outline">Cancel selected events</button>
        </form></details>
        @endif
        @if(config('network.recurring_events') && $items->contains(fn ($item) => in_array($item->status, ['pending', 'approved', 'rejected'], true) && !$item->scheduleStarted() && (!$item->isDateOnly() || \App\Support\EventSchedule::available())))
            <details class="panel form-stack mb-4"><summary>Update shared event details</summary>
                <form id="event-batch-edit" method="post" action="/account/events/revise-selected" class="form-stack mt-3">@csrf
                    @if($filters['series'] ?? null)<input type="hidden" name="series" value="{{ $filters['series'] }}">@endif
                    @foreach($returnFilters as $key => $value)<input type="hidden" name="return_filters[{{ $key }}]" value="{{ $value }}">@endforeach
                    <p class="field-note">Choose up to eight upcoming occurrences below. Update one shared field at a time. Dates and descriptions stay unchanged; title changes also update linked discussions. Changed events require independent review.</p>
                    <label>Field<select name="field"><option value="venue">Venue</option><option value="title">Title</option><option value="organizer">Organizer</option><option value="event_url">Event website</option></select></label>
                    <label>New value<input name="value" maxlength="1024" data-event-batch-value><small class="field-note">Leave blank only to remove an event website.</small></label>
                    <label class="checkbox-line"><input type="checkbox" name="confirm_update" value="1" required> Confirm changes to selected events</label>
                    <button class="button button-outline">Update selected events</button>
                </form>
            </details>
        @endif
        @if(config('network.recurring_events') && $items->contains(fn ($item) => in_array($item->status, ['pending', 'approved', 'rejected'], true) && !$item->scheduleStarted() && (!$item->isDateOnly() || \App\Support\EventSchedule::available())))
            <details class="panel form-stack mb-4"><summary>Reschedule selected events</summary>
                <form id="event-batch-shift" method="post" action="/account/events/shift-selected" class="form-stack mt-3">@csrf
                    @if($filters['series'] ?? null)<input type="hidden" name="series" value="{{ $filters['series'] }}">@endif
                    @foreach($returnFilters as $key => $value)<input type="hidden" name="return_filters[{{ $key }}]" value="{{ $value }}">@endforeach
                    <p class="field-note">Move up to eight upcoming occurrences by the same number of calendar days. Local start and end times stay the same; changed dates require independent review.</p>
                    <label>Days to move<input type="number" name="days" min="-30" max="30" required><small class="field-note">Use a negative number to move earlier or a positive number to move later.</small></label>
                    <label class="checkbox-line"><input type="checkbox" name="confirm_shift" value="1" required> Confirm rescheduling</label>
                    <button class="button button-outline">Reschedule selected events</button>
                </form>
            </details>
        @endif
        <section class="seller-listing-grid">
            @forelse($items as $item)
                <article class="panel seller-listing-card">
                    <div class="event-discovery-card"><div class="event-date-tile"><strong>{{ $item->localStart()->format('j') }}</strong><span>{{ $item->localStart()->format('M') }}</span></div><div><span class="member-listing-status" data-status="{{ $item->status }}">{{ ucfirst($item->status) }}</span><h2>{{ $item->title }}</h2><p>{{ $item->startLabel('M j, Y · g:i a') }} · {{ $item->location->name }}</p><p>{{ $item->venue }}</p><p class="field-note">{{ \App\Support\Community::EVENTS[$item->category] }} · Reference #{{ $item->id }}</p></div></div>
                    @if(isset($series[$item->id]))
                        <p class="field-note">Series occurrence {{ $series[$item->id]->position }} of {{ $series[$item->id]->original_count }} · {{ ucfirst($series[$item->id]->frequency) }} at submission</p>
                        @if(\App\Support\EventSeriesIdentity::enabled())<a class="text-link" href="/account/events?series={{ $series[$item->id]->series_key }}">Manage this series</a>@endif
                    @endif
                    <p class="seller-lifecycle-note">{{ match($item->status) { 'pending' => 'Waiting for moderator approval.', 'approved' => 'Approved for the community calendar.', 'rejected' => 'Not approved for publication.', 'cancelled' => 'Public event marked cancelled.', 'withdrawn' => 'Submission withdrawn before publication.', default => ucfirst($item->status) } }}</p>
                    @if($item->cancellation_note)<p class="field-note">Private cancellation note · {{ $item->cancellation_note }}</p>@endif
                    @if(in_array($item->status, ['approved','cancelled'], true))<a class="button button-outline" href="/events/{{ $item->id }}">View event</a>@endif
                    @if(in_array($item->status, ['pending', 'approved', 'rejected'], true) && !$item->scheduleStarted() && (!$item->isDateOnly() || \App\Support\EventSchedule::available()))
                        @if(!array_key_exists($item->id, $linked) || ($linked[$item->id] && \App\Support\EventDiscussions::editable($item, $linked[$item->id])))
                            <a class="button button-outline" href="/account/events/{{ $item->id }}/edit{{ $editParams ? '?'.http_build_query($editParams, '', '&', PHP_QUERY_RFC3986) : '' }}">Edit event</a>
                        @else
                            <p class="field-note">The linked discussion cannot currently be edited. You can cancel and reuse the event.</p>
                        @endif
                    @endif
                    @if(config('network.recurring_events') && in_array($item->status, ['pending', 'approved', 'rejected'], true) && !$item->scheduleStarted() && (!$item->isDateOnly() || \App\Support\EventSchedule::available()))
                        <label class="checkbox-line"><input form="event-batch-edit" type="checkbox" name="events[]" value="{{ $item->id }}"> Select for shared detail update</label>
                        <input form="event-batch-edit" type="hidden" name="versions[{{ $item->id }}]" value="{{ $item->contentVersion() }}">
                        @if($linked[$item->id] ?? null)<input form="event-batch-edit" type="hidden" name="thread_versions[{{ $item->id }}]" value="{{ $linked[$item->id]->contentVersion() }}">@endif
                        <label class="checkbox-line"><input form="event-batch-shift" type="checkbox" name="events[]" value="{{ $item->id }}"> Select for rescheduling</label>
                        <input form="event-batch-shift" type="hidden" name="versions[{{ $item->id }}]" value="{{ $item->contentVersion() }}">
                    @endif
                    <a class="text-link" href="{{ app(\App\Support\Community::class)->enabled(request()->attributes->get('site'), 'community') ? '/community/new?type=event&reuse='.$item->id : '/events/new?reuse='.$item->id }}">Reuse event details</a>
                    @if($item->status === 'pending' || ($item->status === 'approved' && !($item->localEnd() ?? $item->localStart())->isPast()))
                        <label class="checkbox-line"><input form="event-batch-cancel" type="checkbox" name="events[]" value="{{ $item->id }}"> Select for batch cancellation</label><input form="event-batch-cancel" type="hidden" name="versions[{{ $item->id }}]" value="{{ $item->contentVersion() }}">
                        <details class="seller-remove-listing"><summary>{{ $item->status === 'pending' ? 'Withdraw submission' : 'Cancel event' }}</summary>
                            <form method="post" action="/account/events/{{ $item->id }}/cancel" class="form-stack mt-3">@csrf<input type="hidden" name="version" value="{{ $item->contentVersion() }}">
                                @if($filters['series'] ?? null)<input type="hidden" name="series" value="{{ $filters['series'] }}">@endif
                    @foreach($returnFilters as $key => $value)<input type="hidden" name="return_filters[{{ $key }}]" value="{{ $value }}">@endforeach
                                <label>Private cancellation note<input name="cancellation_note" maxlength="300"></label><button class="button button-outline">{{ $item->status === 'pending' ? 'Confirm withdrawal' : 'Confirm cancellation' }}</button>
                            </form>
                        </details>
                    @endif
                </article>
            @empty<div class="panel market-results-empty"><h2>{{ array_filter($filters, fn ($value) => $value !== null && $value !== '') ? 'No events match these filters.' : 'No events submitted yet.' }}</h2><a class="button button-outline" href="/account/events">View all my events</a></div>@endforelse
        </section>
        {{ $items->links() }}
    </div>
</x-layouts.site>
