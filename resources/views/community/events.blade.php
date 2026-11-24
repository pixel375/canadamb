<x-layouts.site title="Events in Discussions">
    <div class="container page-shell narrow">
        @php($discussionAvailable = app(\App\Support\Community::class)->enabled(request()->attributes->get('site'), 'community'))
        @if($discussionAvailable)<a class="text-link mb-4" href="/community">Back to Discussions</a>@endif
        <div class="page-intro"><div><h1>Community events</h1><p>Find upcoming events in {{ request()->attributes->get('site')->location?->name ?? 'Manitoba' }}.</p></div><a class="button button-primary" href="{{ $discussionAvailable ? '/community/new?type=event' : '/events/new' }}">Submit an event</a></div>
        <details class="search-disclosure" @if(!empty($filters)) open @endif>
            <summary><x-icon name="search" size="18"/> Event filters <x-icon class="disclosure-arrow" name="chevron-down" size="17"/></summary>
            <form method="get" action="/events" class="panel filter-bar">
            <label>Search events<input type="search" name="q" maxlength="100" value="{{ $filters['q'] ?? '' }}"></label>
            <label>Category<select name="category"><option value="">All categories</option>@foreach(\App\Support\Community::EVENTS as $id => $label)<option value="{{ $id }}" @selected(($filters['category'] ?? null) === $id)>{{ $label }}</option>@endforeach</select></label>
            <label>Calendar month<input type="month" name="month" value="{{ $filters['month'] ?? '' }}"></label>
            <button class="button button-primary">Filter events</button>
        </form>
        </details>
        @if($month)
            @php
                $calendarFilters = array_filter(['q' => $filters['q'] ?? null, 'category' => $filters['category'] ?? null]);
                $currentMonth = $month->format('Y-m');
                $calendarUrl = fn (array $values) => '/events?'.http_build_query($calendarFilters + $values);
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
            </section>
        @else<a class="button button-outline mb-4" href="/events?{{ http_build_query(array_filter($filters) + ['month' => now('America/Winnipeg')->format('Y-m')]) }}">Open this month’s calendar</a>@endif
        <div class="panel">
            @forelse($items as $item)
                <article class="item-row"><div>
                    <p class="section-label">{{ $item->localStart()->format('M j · g:i a T') }} · {{ $item->location->name }}</p>
                    <h2><a href="/events/{{ $item->id }}">{{ $item->title }}</a></h2>
                    <p>{{ $item->venue }} · {{ \App\Support\Community::EVENTS[$item->category] }}</p>
                </div></article>
            @empty<p>No upcoming events match these filters.</p>@endforelse
        </div>
        {{ $items->links() }}
    </div>
</x-layouts.site>
