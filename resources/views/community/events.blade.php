<x-layouts.site title="Events calendar" :canonical="$canonical">
    <div class="container page-shell narrow">
        <nav aria-label="Breadcrumb"><a class="text-link" href="/discussions">Discussions</a></nav>
        <div class="page-intro"><div><h1>Events calendar</h1><p>Times shown in America/Winnipeg.</p></div>
            <a class="button button-outline" href="/events/new">Request an event listing</a></div>
        <details class="search-disclosure" @if(array_filter($filters)) open @endif>
            <summary>Event filters</summary>
            <form method="get" action="/events" class="panel filter-bar">
                <label>Search events<input type="search" name="q" maxlength="100" value="{{ $filters['q'] ?? '' }}"></label>
                <label>Category<select name="category"><option value="">All categories</option>
                    @foreach(\App\Support\Community::EVENTS as $id => $label)<option value="{{ $id }}" @selected(($filters['category'] ?? null) === $id)>{{ $label }}</option>@endforeach
                </select></label>
                @if(request()->attributes->get('site')->isNetwork())
                    <label>Location<select name="location"><option value="">All Manitoba</option>
                        @foreach($places as $place)<option value="{{ $place->id }}" @selected((int) ($filters['location'] ?? 0) === $place->id)>{{ $place->name }}</option>@endforeach
                    </select></label>
                @endif
                <label>Calendar month<input type="month" name="month" value="{{ $month->format('Y-m') }}"></label>
                <button class="button button-primary">Filter events</button>
            </form>
        </details>
        @php
            $calendarFilters = array_filter(\Illuminate\Support\Arr::only($filters, ['q', 'category', 'location']), fn ($value) => $value !== null && $value !== '');
            $monthUrl = fn ($date, $day = null) => '/events?'.http_build_query($calendarFilters + ['month' => $date->format('Y-m'), 'day' => $day]);
        @endphp
        <section class="panel form-stack" aria-label="Monthly event calendar">
            <div class="event-agenda-nav">
                <a class="text-link" href="{{ $monthUrl($month->subMonth()) }}">Previous month</a>
                <h2>{{ $month->format('F Y') }}</h2>
                <a class="text-link" href="{{ $monthUrl($month->addMonth()) }}">Next month</a>
            </div>
            <div class="inline-actions">
                <a class="text-link" href="{{ $monthUrl($today, $today->toDateString()) }}">Today</a>
                @if($selectedDay)<a class="text-link" href="{{ $monthUrl($month) }}">Show the whole month</a>@endif
            </div>
            @forelse($calendar as $date => $group)
                <details class="agenda-day" @if($loop->first || $group['day']->isSameDay($today)) open @endif>
                    <summary @if($group['day']->isSameDay($today)) aria-current="date" @endif>
                        <span><time datetime="{{ $date }}">{{ $group['day']->format('l, F j') }}</time>
                            @if($group['day']->isSameDay($today)) · Today @elseif($group['day']->isSameDay($today->addDay())) · Tomorrow @endif</span>
                        <span>{{ $group['count'] }} {{ \Illuminate\Support\Str::plural('event', $group['count']) }}</span>
                    </summary>
                    @foreach($group['events'] as $item)
                        <article class="agenda-item">
                            <h3><a class="text-link" href="{{ $item->publicPath() }}">{{ $item->title }}</a></h3>
                            <p>
                                @if($item->status === 'cancelled')<strong>Cancelled</strong> · @endif
                                @if($item->localStart()->isSameDay($group['day']))
                                    <time datetime="{{ $item->localStart()->toAtomString() }}">{{ $item->localStart()->format('g:i a T') }}</time>
                                @else Continues @endif
                                @if($item->localEnd()) · Ends <time datetime="{{ $item->localEnd()->toAtomString() }}">{{ $item->localEnd()->format($item->localEnd()->isSameDay($group['day']) ? 'g:i a T' : 'M j, g:i a T') }}</time>@endif
                            </p>
                            <p class="field-note">{{ $item->venue }} · {{ $item->place_name }} · {{ \App\Support\Community::EVENTS[$item->category] ?? 'Event' }}</p>
                        </article>
                    @endforeach
                    @if(!$selectedDay && $group['count'] > count($group['events']))
                        <a class="text-link" href="{{ $monthUrl($month, $date) }}">View all {{ $group['count'] }} events</a>
                    @endif
                </details>
            @empty<p>No events match this date range and filters.</p>@endforelse
            {{ $pagination?->links() }}
        </section>
    </div>
</x-layouts.site>
