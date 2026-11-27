<?php
    $forumSite = request()->attributes->get('site');
    $forumPlace = \App\Support\LocationScope::forBrowsing($forumSite, $filters['location'] ?? null);
    $postParams = array_filter(['location' => $forumSite->isNetwork() ? $forumPlace?->slug : null, 'category' => $filters['category'] ?? null]);
    $postUrl = '/community/new'.($postParams ? '?'.http_build_query($postParams) : '');
    $categoryParams = array_filter(array_diff_key($filters, ['category' => true]), fn ($value) => $value !== null && $value !== '');
    $eventUrl = '/community/new?'.http_build_query(['type' => 'event'] + array_intersect_key($postParams, ['location' => true]));
?>
<x-layouts.site title="Discussions" :location="$forumPlace">
    <div class="container page-shell">
        <div class="page-intro discussion-intro">
            <div>
                <p class="section-label">Manitoba community</p>
                <h1>Discussions</h1>
                <p>Ask questions, share recommendations, and see what's happening nearby.</p>
            </div>
            <div class="discussion-intro-actions">
                @auth
                    @if(auth()->user()->hasVerifiedEmail())
                        <a class="button button-outline" href="/account/discussions">My discussions</a>
                        <a class="button button-outline" href="/account/followed-discussions"><x-icon name="bell" size="17"/> Following</a>
                    @endif
                @endauth
                <a class="button button-primary" href="{{ $postUrl }}"><x-icon name="plus" size="17"/> Start a discussion</a>
                @if($eventsEnabled)
                    <a class="button button-outline" href="{{ $eventUrl }}"><x-icon name="calendar" size="17"/> Submit an event</a>
                @endif
            </div>
        </div>

        <div class="discussion-hub">
            @if($eventsEnabled)<nav class="discussion-mobile-jumps" aria-label="Jump to discussion sections"><a class="button button-outline" href="#discussion-feed-title">Topics</a><a class="button button-outline" href="#discussion-events-title"><x-icon name="calendar" size="16"/> Upcoming events</a></nav>@endif
            <section class="discussion-feed" aria-labelledby="discussion-feed-title">
                <div class="discussion-section-heading">
                    <div><p class="section-label">Local conversations</p><h2 id="discussion-feed-title">Browse discussions</h2></div>
                    <span class="discussion-count">{{ $threads->total() }} {{ \Illuminate\Support\Str::plural('topic', $threads->total()) }}</span>
                </div>
                <nav class="discussion-category-nav" aria-label="Discussion categories">
                    <a class="discussion-filter-chip" href="/community{{ $categoryParams ? '?'.http_build_query($categoryParams) : '' }}" aria-current="{{ isset($filters['category']) ? 'false' : 'page' }}">All topics <small>{{ $categoryCounts->sum() }}</small></a>
                    @foreach(\App\Support\Community::FORUMS as $key => $label)
                        <a class="discussion-filter-chip" href="/community?{{ http_build_query(['category' => $key] + $categoryParams) }}" aria-current="{{ ($filters['category'] ?? null) === $key ? 'page' : 'false' }}">{{ $label }} <small>{{ $categoryCounts[$key] ?? 0 }}</small></a>
                    @endforeach
                </nav>
                <details class="search-disclosure" {{ !empty($filters) ? 'open' : '' }}>
                    <summary><x-icon name="search" size="18"/> Search discussions <x-icon class="disclosure-arrow" name="chevron-down" size="17"/></summary>
                    <form method="get" action="/community" class="panel filter-bar">
                        <label>Search discussions<input type="search" name="q" maxlength="100" value="{{ $filters['q'] ?? '' }}" placeholder="Search conversations"></label>
                        <label>Category<select name="category"><option value="">All categories</option>@foreach(\App\Support\Community::FORUMS as $key => $label)<option value="{{ $key }}" @selected(($filters['category'] ?? null) === $key)>{{ $label }}</option>@endforeach</select></label>
                        @if(request()->attributes->get('site')->isNetwork())
                            <label>Location<select name="location"><option value="">All Manitoba</option>@foreach($places as $place)<option value="{{ $place->slug }}" @selected(($filters['location'] ?? null) === $place->slug)>{{ $place->name }}</option>@endforeach</select></label>
                        @endif
                        <label>Topic status<select name="topics"><option value="">All public topics</option><option value="open" @selected(($filters['topics'] ?? '') === 'open')>Open for replies</option><option value="unanswered" @selected(($filters['topics'] ?? '') === 'unanswered')>Unanswered and open</option>@if(auth()->user()?->hasVerifiedEmail())<option value="following" @selected(($filters['topics'] ?? '') === 'following')>Topics I follow</option>@endif</select></label>
                        <label>Recent activity<select name="activity"><option value="">Any time</option><option value="7" @selected(($filters['activity'] ?? '') === '7')>Past 7 days</option><option value="30" @selected(($filters['activity'] ?? '') === '30')>Past 30 days</option></select></label>
                        <label>Sort by<select name="sort">@foreach(['recent' => 'Recent activity', 'newest' => 'Newest topics', 'replies' => 'Most replies'] as $key => $label)<option value="{{ $key }}" @selected(($filters['sort'] ?? 'recent') === $key)>{{ $label }}</option>@endforeach</select></label>
                        <button class="button button-primary" type="submit">Search</button>
                    </form>
                </details>
                <div class="panel discussion-topics">
                    @if($threads->isEmpty())
                        @php
                            $activeFilters = array_filter($filters, fn ($value) => $value !== null && $value !== '');
                            $scope = array_intersect_key($activeFilters, ['location' => true]);
                            $wideFilters = array_diff_key($activeFilters, ['location' => true]);
                        @endphp
                        <div class="discussion-empty">
                            <span class="discussion-empty-icon"><x-icon name="discussions" size="28"/></span>
                            <h3>{{ empty($activeFilters) ? 'Start the conversation' : 'No discussions match these filters' }}</h3>
                            <p>{{ empty($activeFilters) ? 'Have a question, a local tip, or something to share? Start the first discussion in your community.' : 'Try another category, or clear your filters to see more conversations.' }}</p>
                            <div class="discussion-empty-actions">
                                @if($wideFilters)
                                    <a class="button button-outline" href="{{ '/community'.($scope ? '?'.http_build_query($scope) : '') }}">Clear search filters</a>
                                @endif
                                @if($scope)
                                    <a class="button button-outline" href="{{ '/community'.($wideFilters ? '?'.http_build_query($wideFilters) : '') }}">Search all Manitoba</a>
                                @endif
                                <a class="button button-primary" href="{{ $postUrl }}"><x-icon name="plus" size="16"/> Start a discussion</a>
                            </div>
                        </div>
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
                                <p class="discussion-topic-excerpt">{{ \Illuminate\Support\Str::limit($thread->body, 150) }}</p>
                                <div class="discussion-topic-footer">
                                    <span class="discussion-topic-author">
                                        <span class="discussion-mini-avatar" aria-hidden="true">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($thread->author?->name ?? 'M', 0, 1)) }}</span>
                                        @if($thread->author)
                                            <a href="/users/{{ $thread->author->id }}">{{ $thread->author->name }}</a>
                                        @else
                                            Former member
                                        @endif
                                    </span>
                                    <span><x-icon name="messages" size="15"/> {{ $thread->replies_count }} {{ \Illuminate\Support\Str::plural('reply', $thread->replies_count) }}</span>
                                    <span><x-icon name="clock" size="15"/> Active {{ $thread->last_activity_at?->diffForHumans() ?? 'recently' }}</span>
                                    @if($thread->locked)<span class="discussion-topic-locked">Locked</span>@endif
                                </div>
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
                        <a class="discussion-events-browse" href="{{ \App\Support\LocationScope::browseUrl(request()->attributes->get('site'), '/events', \App\Support\LocationScope::forBrowsing(request()->attributes->get('site'), $filters['location'] ?? null)) }}" aria-label="Open the full events calendar"><x-icon name="arrow" size="19"/></a>
                    </div>
                    <div class="discussion-timeline">
                        @if($eventDays->isEmpty())
                            <div class="discussion-events-empty">
                                <x-icon name="calendar" size="27"/>
                                <p>No upcoming events posted yet.</p>
                                <a class="text-link" href="{{ $eventUrl }}">Submit an event</a>
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
                                                <time datetime="{{ $event->startDateValue() }}">{{ $event->localStart()->toDateString() < $date ? 'Ongoing' : $event->startLabel('g:i a') }}</time>
                                                <h3><a href="/events/{{ $event->id }}">{{ $event->title }}</a></h3>
                                                <p>{{ $event->venue }} · {{ $event->location->name }}</p>
                                                @if($linkedDiscussion = \App\Support\EventDiscussions::publicThread(request()->attributes->get('site'), $event))<a class="text-link" href="/community/{{ $linkedDiscussion->id }}">Discussion</a>@endif
                                            </article>
                                        @endforeach
                                    </div>
                                </details>
                            @endforeach
                        @endif
                    </div>
                    <div class="discussion-events-footer">
                        <a class="text-link" href="{{ \App\Support\LocationScope::browseUrl(request()->attributes->get('site'), '/events', \App\Support\LocationScope::forBrowsing(request()->attributes->get('site'), $filters['location'] ?? null)) }}">Full events calendar <x-icon name="arrow" size="16"/></a>
                        <a class="text-link" href="{{ $eventUrl }}">Suggest an event</a>
                    </div>
                </aside>
            @endif
        </div>
    </div>
</x-layouts.site>
