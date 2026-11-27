<?php
    $eventSite = request()->attributes->get('site');
    $createEventUrl = app(\App\Support\Community::class)->enabled($eventSite, 'community') ? '/community/new?type=event' : '/events/new';
    if ($eventSite->isNetwork() && $place) {
        $createEventUrl .= (str_contains($createEventUrl, '?') ? '&' : '?').'location='.rawurlencode($place->slug);
    }
?>
<x-layouts.site title="Events in Discussions" :location="$place">
    <div class="container page-shell browse-page">
        <div class="page-intro"><div><h1>Community events</h1><p>Find upcoming events in {{ $place?->name ?? 'Manitoba' }}.</p></div><a class="button button-primary" href="{{ $createEventUrl }}">Submit an event</a></div>
        <div class="inline-actions mb-4">
            @if(app(\App\Support\Community::class)->enabled($eventSite, 'community'))<a class="button button-outline" href="{{ \App\Support\LocationScope::browseUrl(request()->attributes->get('site'), '/community', $place) }}"><x-icon name="discussions" size="17"/> Discussions</a>@endif
            @auth<a class="button button-outline" href="/account/events">My events</a>@endauth
        </div>
        @php
            $periodParams = \App\Support\LocationScope::preserveAll(array_filter(array_diff_key($filters, ['period' => true, 'month' => true, 'day' => true]), fn ($value) => $value !== null && $value !== ''));
            $periodUrl = fn (?string $period) => '/events'.(($query = http_build_query($periodParams + ($period ? ['period' => $period] : []))) ? '?'.$query : '');
        @endphp
        <nav class="discussion-category-nav mb-4" aria-label="Event date shortcuts">
            @foreach(['' => 'All upcoming', 'today' => 'Today', 'week' => 'Next 7 days', 'weekend' => 'This weekend', 'month' => 'Next 30 days'] as $key => $label)
                <a class="discussion-filter-chip" href="{{ $periodUrl($key ?: null) }}" @if(($filters['period'] ?? '') === $key && !isset($filters['month']) && !isset($filters['day'])) aria-current="page" @endif>{{ $label }}</a>
            @endforeach
        </nav>
        <div class="browse-workspace">
        <details class="search-disclosure browse-sidebar" @if(!empty($filters)) open @endif>
            <summary><x-icon name="search" size="18"/> Event filters <x-icon class="disclosure-arrow" name="chevron-down" size="17"/></summary>
            <form method="get" action="/events" class="panel filter-bar">
            <label>Search events<input type="search" name="q" maxlength="100" value="{{ $filters['q'] ?? '' }}"></label>
            <label>Category<select name="category"><option value="">All categories</option>@foreach(\App\Support\Community::EVENTS as $id => $label)<option value="{{ $id }}" @selected(($filters['category'] ?? null) === $id)>{{ $label }}</option>@endforeach</select></label>
            <label>Period<select name="period"><option value="">All upcoming</option>@foreach(['today' => 'Today', 'week' => 'Next 7 days', 'weekend' => 'This weekend', 'month' => 'Next 30 days'] as $key => $label)<option value="{{ $key }}" @selected(($filters['period'] ?? '') === $key)>{{ $label }}</option>@endforeach</select></label>
            <label>Calendar month<input type="month" name="month" value="{{ $filters['month'] ?? '' }}"></label>
            <label>Specific date<input type="date" name="day" value="{{ $filters['day'] ?? '' }}"></label>
            @if($places->isNotEmpty())<label>Location<select name="location"><option value="">All Manitoba</option>@foreach($places as $option)<option value="{{ $option->slug }}" @selected($place?->id === $option->id)>{{ $option->name }}</option>@endforeach</select></label>@endif
            <button class="button button-primary">Filter events</button>
        </form>
        </details>
        <div class="browse-results-column">
        @if($month)
            @php
                $calendarFilters = array_filter(['q' => $filters['q'] ?? null, 'category' => $filters['category'] ?? null, 'location' => $filters['location'] ?? null, 'period' => $filters['period'] ?? null], fn ($value) => $value !== null && $value !== '');
                $currentMonth = $month->format('Y-m');
                $calendarUrl = fn (array $values) => '/events?'.http_build_query(\App\Support\LocationScope::preserveAll($calendarFilters + $values));
            @endphp
            <section class="panel mb-4" aria-label="Monthly event calendar">
                <div class="item-row"><a class="text-link" href="{{ $calendarUrl(['month' => $month->subMonth()->format('Y-m')]) }}">Previous</a><h2>{{ $month->format('F Y') }}</h2><a class="text-link" href="{{ $calendarUrl(['month' => $month->addMonth()->format('Y-m')]) }}">Next</a></div>
                <div class="calendar-grid">
                    @foreach(['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $weekday)<span class="calendar-label">{{ $weekday }}</span>@endforeach
                    @for($i = 1; $i < $month->dayOfWeekIso; $i++)<span aria-hidden="true"></span>@endfor
                    @for($number = 1; $number <= $month->daysInMonth; $number++)
                        @php($date = $month->setDay($number)->toDateString())
                        @if(isset($calendar[$date]))
                            <a class="calendar-day calendar-active" href="{{ $calendarUrl(['month' => $currentMonth, 'day' => $date]) }}" aria-label="{{ $month->setDay($number)->format('F j') }} · {{ $calendar[$date] }} {{ \Illuminate\Support\Str::plural('event', $calendar[$date]) }}" @if(($filters['day'] ?? null) === $date) aria-current="date" @endif>{{ $number }} <span aria-hidden="true">· {{ $calendar[$date] }}</span></a>
                        @else<span class="calendar-day">{{ $number }}</span>@endif
                    @endfor
                </div>
                @if(isset($filters['day']))<a class="text-link mt-3" href="{{ $calendarUrl(['month' => $currentMonth]) }}">Show the whole month</a>@endif
                <div class="form-stack mt-4" aria-label="Events by day">
                    <h3>Day-by-day agenda</h3>
                    @foreach(collect($agenda)->sortKeys() as $date => $entries)
                        @if(isset($filters['day']) && $filters['day'] !== $date) @continue @endif
                        <details @if(($filters['day'] ?? null) === $date) open @endif>
                            <summary>{{ \Carbon\CarbonImmutable::parse($date, 'America/Winnipeg')->format('l, M j') }} · {{ $calendar[$date] }} {{ \Illuminate\Support\Str::plural('event', $calendar[$date]) }}</summary>
                            <div class="form-stack mt-2">@foreach($entries as $entry)
                                <div><a class="text-link" href="{{ \App\Support\LocationScope::detailUrl('/events/'.$entry->id) }}">{{ $entry->title }}</a><p class="field-note">{{ $entry->venue }}</p></div>
                            @endforeach
                            <a class="text-link" href="{{ $calendarUrl(['month' => $currentMonth, 'day' => $date]) }}">View all events this day</a></div>
                        </details>
                    @endforeach
                    @if(!$agenda || (isset($filters['day']) && !isset($agenda[$filters['day']])))<p class="field-note">No upcoming events in this selection.</p>@endif
                </div>
            </section>
        @else<a class="button button-outline mb-4" href="/events?{{ http_build_query(\App\Support\LocationScope::preserveAll(array_filter($filters) + ['month' => now('America/Winnipeg')->format('Y-m')])) }}">Open this month’s calendar</a>@endif
        @if($items->total())
            <div class="inline-actions mb-4"><a class="button button-outline" href="/events/calendar?{{ http_build_query(array_filter($filters, fn ($value) => $value !== null && $value !== '')) }}">Download matching events (.ics)</a><span class="field-note">Calendar snapshot · {{ min($items->total(), 50) }} of {{ $items->total() }} events, earliest first. Future updates are not synchronized.</span></div>
        @endif
        <div class="section-heading"><h2>{{ $items->total() }} matching {{ \Illuminate\Support\Str::plural('event', $items->total()) }}</h2><a class="text-link" href="{{ \App\Support\LocationScope::browseUrl($eventSite, '/events', $place) }}">Clear filters</a></div>
        <div class="browse-feed">
            @forelse($items as $item)
                <article class="panel event-discovery-card"><div class="event-date-tile"><strong>{{ $item->localStart()->format('j') }}</strong><span>{{ $item->localStart()->format('M') }}</span></div><div>
                    <p class="section-label">{{ $item->startLabel('M j · g:i a T') }} · {{ $item->location->name }}</p>
                    <h2><a href="{{ \App\Support\LocationScope::detailUrl('/events/'.$item->id) }}">{{ $item->title }}</a></h2>
                    <p>{{ $item->venue }} · {{ \App\Support\Community::EVENTS[$item->category] }}</p>
                    @if(!$item->isDateOnly() && $item->localStart()->isPast() && $item->localEnd()?->isFuture())<p class="field-note">In progress</p>@endif
                    @if($item->endLabel())<p class="field-note">Ends {{ $item->endLabel('M j · g:i a T') }}</p>@endif<p class="field-note">{{ \Illuminate\Support\Str::limit(\Illuminate\Support\Str::squish($item->body), 160) }}</p><a class="text-link" href="{{ \App\Support\LocationScope::detailUrl('/events/'.$item->id) }}">Event details <x-icon name="arrow" size="16"/></a>
                    @if($linkedDiscussion = \App\Support\EventDiscussions::publicThread($eventSite, $item))<a class="text-link" href="{{ \App\Support\LocationScope::detailUrl('/community/'.$linkedDiscussion->id) }}">Discussion <x-icon name="messages" size="16"/></a>@endif
                </div></article>
            @empty<div class="panel market-results-empty"><h3>No upcoming events match these filters.</h3><a class="button button-outline" href="{{ \App\Support\LocationScope::browseUrl(request()->attributes->get('site'), '/events', $place) }}">Clear filters</a></div>@endforelse
        </div>
        {{ $items->links() }}
        </div>
        </div>
    </div>
</x-layouts.site>
