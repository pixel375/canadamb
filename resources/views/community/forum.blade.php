<x-layouts.site title="Discussions">
    <div class="container page-shell">
        <div class="page-intro discussion-intro">
            <div>
                <p class="section-label">Manitoba community</p>
                <h1>Discussions</h1>
                <p>Ask questions, share recommendations, and see what's happening nearby.</p>
            </div>
            <div class="discussion-intro-actions">
                <a class="button button-primary" href="/community/new"><x-icon name="plus" size="17"/> Start a discussion</a>
                @if($eventsEnabled)
                    <a class="button button-outline" href="/community/new?type=event"><x-icon name="calendar" size="17"/> Submit an event</a>
                @endif
            </div>
        </div>

        <div class="discussion-hub">
            <section class="discussion-feed" aria-labelledby="discussion-feed-title">
                <div class="discussion-section-heading">
                    <div><p class="section-label">Local conversations</p><h2 id="discussion-feed-title">Browse discussions</h2></div>
                    <span class="discussion-count">{{ $threads->total() }} {{ \Illuminate\Support\Str::plural('topic', $threads->total()) }}</span>
                </div>
                <nav class="discussion-category-nav" aria-label="Discussion categories">
                    <a class="discussion-filter-chip" href="/community{{ isset($filters['q']) ? '?'.http_build_query(['q' => $filters['q']]) : '' }}" aria-current="{{ isset($filters['category']) ? 'false' : 'page' }}">All topics</a>
                    @foreach(\App\Support\Community::FORUMS as $key => $label)
                        <a class="discussion-filter-chip" href="/community?{{ http_build_query(array_filter(['category' => $key, 'q' => $filters['q'] ?? null])) }}" aria-current="{{ ($filters['category'] ?? null) === $key ? 'page' : 'false' }}">{{ $label }}</a>
                    @endforeach
                </nav>
                <details class="search-disclosure" {{ !empty($filters) ? 'open' : '' }}>
                    <summary><x-icon name="search" size="18"/> Search discussions <x-icon class="disclosure-arrow" name="chevron-down" size="17"/></summary>
                    <form method="get" action="/community" class="panel filter-bar">
                        <label>Search discussions<input type="search" name="q" maxlength="100" value="{{ $filters['q'] ?? '' }}" placeholder="Search conversations"></label>
                        <label>Category<select name="category"><option value="">All categories</option>@foreach(\App\Support\Community::FORUMS as $key => $label)<option value="{{ $key }}" @selected(($filters['category'] ?? null) === $key)>{{ $label }}</option>@endforeach</select></label>
                        <button class="button button-primary" type="submit">Search</button>
                    </form>
                </details>
                <div class="panel discussion-topics">
                    @if($threads->isEmpty())
                        <div class="discussion-empty"><x-icon name="discussions" size="28"/><h3>No discussions found</h3><p>Try another category, or start a conversation for your community.</p></div>
                    @else
                        @foreach($threads as $thread)
                            <article class="discussion-topic">
                                <div class="discussion-topic-meta">
                                    <span>{{ \App\Support\Community::FORUMS[$thread->category] }}</span>
                                    <span aria-hidden="true">·</span>
                                    <span>{{ $thread->location?->name ?? 'Manitoba' }}</span>
                                    @if($thread->pinned)
                                        <span class="discussion-topic-pill">Pinned</span>
                                    @endif
                                </div>
                                <h3><a href="/community/{{ $thread->id }}">{{ $thread->title }}</a></h3>
                                <p>{{ $thread->replies_count }} {{ \Illuminate\Support\Str::plural('reply', $thread->replies_count) }} · Active {{ $thread->last_activity_at?->diffForHumans() ?? 'recently' }}{{ $thread->locked ? ' · Locked' : '' }}</p>
                            </article>
                        @endforeach
                    @endif
                </div>
                {{ $threads->links() }}
            </section>

            @if($eventsEnabled)
                <aside class="discussion-events" aria-labelledby="discussion-events-title">
                    <div class="discussion-events-heading">
                        <div>
                            <p class="section-label"><x-icon name="calendar" size="16"/> Local calendar</p>
                            <h2 id="discussion-events-title">Upcoming events</h2>
                            <p>Events over the next 60 days.</p>
                        </div>
                        <a class="discussion-events-browse" href="/events" aria-label="Open the full events calendar"><x-icon name="arrow" size="19"/></a>
                    </div>
                    <div class="discussion-timeline">
                        @if($eventDays->isEmpty())
                            <div class="discussion-events-empty">
                                <x-icon name="calendar" size="27"/>
                                <p>No upcoming events posted yet.</p>
                                <a class="text-link" href="/community/new?type=event">Submit an event</a>
                            </div>
                        @else
                            @foreach($eventDays as $date => $dayEvents)
                                @php($day = \Carbon\CarbonImmutable::parse($date, 'America/Winnipeg'))
                                <details class="discussion-day" {{ $loop->first ? 'open' : '' }}>
                                    <summary>
                                        <span class="discussion-day-date"><strong>{{ $day->format('j') }}</strong><small>{{ $day->format('M') }}</small></span>
                                        <span class="discussion-day-copy"><strong>{{ $day->format('l') }}</strong><small>{{ $dayEvents->count() }} {{ \Illuminate\Support\Str::plural('event', $dayEvents->count()) }}</small></span>
                                        <x-icon name="chevron-down" size="18"/>
                                    </summary>
                                    <div class="discussion-day-events">
                                        @foreach($dayEvents as $event)
                                            <article class="discussion-event">
                                                <time datetime="{{ $event->localStart()->toAtomString() }}">{{ $event->localStart()->format('g:i a') }}</time>
                                                <h3><a href="/events/{{ $event->id }}">{{ $event->title }}</a></h3>
                                                <p>{{ $event->venue }} · {{ $event->location->name }}</p>
                                            </article>
                                        @endforeach
                                    </div>
                                </details>
                            @endforeach
                        @endif
                    </div>
                    <div class="discussion-events-footer">
                        <a class="text-link" href="/events">Full events calendar <x-icon name="arrow" size="16"/></a>
                        <a class="text-link" href="/community/new?type=event">Suggest an event</a>
                    </div>
                </aside>
            @endif
        </div>
    </div>
</x-layouts.site>
