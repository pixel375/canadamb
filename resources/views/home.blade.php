@php
    $site = request()->attributes->get('site');
    $enabled = app(\App\Support\Marketplace::class)->enabled($site);
    $place = $location->name ?? 'Manitoba';
    $scope = $site->isNetwork() && $location ? '?location='.$location->slug : '';
    $hero = match ($site->code) { 'network' => 'manitoba', 'brandon' => 'brandon', default => null };
@endphp
<x-layouts.site :preview="$preview ?? false" :location="$location" main-class="home-main">
    <div class="container">
        <div class="home-lead-grid">
        <section class="hero{{ $hero ? ' hero-photo' : '' }}" aria-labelledby="hero-title">
            @if($hero)<img class="hero-image hero-{{ $hero }}" src="/images/{{ $hero }}.webp" alt="" fetchpriority="high">@endif
            <div class="hero-content">
                <p class="hero-location"><x-icon name="pin" size="16"/> {{ $site->location ? $site->location->name.', Manitoba' : 'Manitoba, Canada' }}</p>
                <h1 id="hero-title">{{ $site->tagline }}</h1>
                <p class="hero-description">{{ $site->description }}</p>
                @unless($preview ?? false)
                    <div class="hero-actions">
                        @if($enabled)<a class="button button-primary" href="/marketplace{{ $scope }}">Browse Marketplace <x-icon name="arrow" size="18"/></a>@endif
                        @if($site->isNetwork())<a class="button button-hero" href="/communities">Explore communities</a>
                        @elseif($enabled)<a class="button button-hero" href="/marketplace/new">Post a listing</a>@endif
                    </div>
                @endunless
            </div>
            @if($hero)<a class="hero-credit" href="/photo-credits">{{ $hero === 'brandon' ? 'Brandon from North Hill' : 'Canola fields in Manitoba' }}</a>@endif
        </section>
        <aside class="home-explore-panel" aria-labelledby="home-explore-title">
            <div class="home-explore-heading">
                <p class="section-label">Your local hub</p>
                <h2 id="home-explore-title">Explore {{ $site->location?->name ?? 'Manitoba' }}</h2>
                <p>Find people, places and opportunities near you.</p>
            </div>
            <div class="home-explore-links">
                @if($enabled)
                    <a href="/marketplace{{ $scope }}" class="home-explore-link">
                        <span class="home-explore-icon"><x-icon name="marketplace" size="23"/></span>
                        <span><strong>Marketplace</strong><small>Browse local classifieds</small></span>
                        <x-icon name="arrow" size="17"/>
                    </a>
                @endif
                @if(app(\App\Support\BusinessDirectory::class)->enabled($site))
                    <a href="/businesses" class="home-explore-link">
                        <span class="home-explore-icon"><x-icon name="building" size="23"/></span>
                        <span><strong>Local businesses</strong><small>Shops and services nearby</small></span>
                        <x-icon name="arrow" size="17"/>
                    </a>
                @endif
                @if($networkSettings['modules']['community'] ?? false)
                    <a href="/discussions" class="home-explore-link">
                        <span class="home-explore-icon"><x-icon name="discussions" size="23"/></span>
                        <span><strong>Discussions</strong><small>Connect with your neighbours</small></span>
                        <x-icon name="arrow" size="17"/>
                    </a>
                @endif
                @if($networkSettings['modules']['events'] ?? false)
                    <a href="/events" class="home-explore-link">
                        <span class="home-explore-icon"><x-icon name="calendar" size="23"/></span>
                        <span><strong>Events</strong><small>See what's happening locally</small></span>
                        <x-icon name="arrow" size="17"/>
                    </a>
                @endif
                @if($networkSettings['modules']['jobs'] ?? false)
                    <a href="/jobs" class="home-explore-link">
                        <span class="home-explore-icon"><x-icon name="briefcase" size="23"/></span>
                        <span><strong>Jobs</strong><small>Find opportunities in your area</small></span>
                        <x-icon name="arrow" size="17"/>
                    </a>
                @endif
            </div>
        </aside>
        </div>

        @unless($preview ?? false)
            @if($site->isNetwork())
            <form class="location-bar" action="/" method="get">
                <span class="location-icon"><x-icon name="map"/></span>
                <div><label for="home-location">Explore by location</label><p>Find listings in your part of Manitoba.</p></div>
                <select id="home-location" name="location" aria-label="Browsing location">
                    <option value="">All Manitoba</option>
                    @foreach($locations as $placeOption)
                        <option value="{{ $placeOption->slug }}" @selected($location?->id === $placeOption->id)>{{ $placeOption->name }}</option>
                    @endforeach
                </select>
                <button class="button button-outline" type="submit">Apply</button>
            </form>
            @endif
        @endunless

        @if($articles->isNotEmpty())
            <section class="homepage-section" aria-labelledby="news-title">
                <div class="section-heading"><h2 id="news-title">Latest news</h2>@unless($preview ?? false)<a class="text-link" href="/news">All news <x-icon name="arrow" size="16"/></a>@endunless</div>
                <div class="panel">
                    @foreach($articles as $article)
                        <article class="item-row"><div><p class="section-label">{{ $article->source->name }} · {{ $article->published_at->format('M j, Y') }}</p><h3>@if($preview ?? false){{ $article->title }}@else<a href="/news/{{ $article->id }}">{{ $article->title }}</a>@endif</h3><p>{{ \Illuminate\Support\Str::limit($article->body, 150) }}</p></div></article>
                    @endforeach
                </div>
            </section>
        @endif

        <div class="home-content-grid">
        @if($enabled)
        <section class="homepage-section" aria-labelledby="market-title">
            <div class="section-heading">
                <div><p class="section-label"><x-icon name="marketplace" size="15"/> Marketplace</p><h2 id="market-title">Latest in {{ $place }}</h2></div>
                @unless($preview ?? false)<a class="text-link" href="/marketplace{{ $scope }}">View all listings <x-icon name="arrow" size="16"/></a>@endunless
            </div>
            @if($latest->isEmpty())
                <div class="empty-state">
                    <span class="empty-icon"><x-icon name="tag" size="25"/></span>
                    <div><h3>No active listings yet</h3><p>New listings will appear here after review.</p></div>
                    @unless($preview ?? false)<a class="button button-outline" href="/marketplace/new"><x-icon name="plus" size="16"/> Post a listing</a>@endunless
                </div>
            @else
                <div class="listing-grid">@foreach($latest as $item)<x-listing-card :item="$item" :preview="$preview ?? false"/>@endforeach</div>
            @endif
        </section>
        @endif

        @if(app(\App\Support\BusinessDirectory::class)->enabled($site))
            <section class="homepage-section" aria-labelledby="business-title">
                <div class="section-heading">
                    <h2 id="business-title">Business updates</h2>
                    @unless($preview ?? false)<a class="text-link" href="/business-updates">View all updates <x-icon name="arrow" size="16"/></a>@endunless
                </div>
                <div class="panel">
                    @forelse($businessUpdates as $update)
                        <article class="item-row">
                            <div><h3>{{ $update->title }}</h3><p>{{ $update->branch->business->name }} · {{ $update->branch->location->name }}</p></div>
                            <time datetime="{{ $update->published_at->toDateString() }}">{{ $update->published_at->format('M j, Y') }}</time>
                        </article>
                    @empty<p>No published business updates in {{ $place }}.</p>@endforelse
                </div>
            </section>
        @endif

        </div>

        @if($recentDiscussions->isNotEmpty() || $upcomingEvents->isNotEmpty() || $latestJobs->isNotEmpty())
            <div class="community-activity">
                @if($recentDiscussions->isNotEmpty())
                    <section class="homepage-section" aria-labelledby="discussion-title">
                        <div class="section-heading"><h2 id="discussion-title">Recent discussions</h2>@unless($preview ?? false)<a class="text-link" href="/discussions">All discussions <x-icon name="arrow" size="16"/></a>@endunless</div>
                        <div class="panel">
                            @foreach($recentDiscussions as $thread)
                                <article class="item-row"><div><h3>@if($preview ?? false){{ $thread->title }}@else<a href="/discussions/{{ $thread->id }}">{{ $thread->title }}</a>@endif</h3><p>{{ $thread->location?->name ?? 'Manitoba' }} · {{ $thread->replies_count }} {{ \Illuminate\Support\Str::plural('reply', $thread->replies_count) }}</p></div></article>
                            @endforeach
                        </div>
                    </section>
                @endif
                @if($upcomingEvents->isNotEmpty())
                    <section class="homepage-section" aria-labelledby="events-title">
                        <div class="section-heading"><h2 id="events-title">Upcoming events</h2>@unless($preview ?? false)<a class="text-link" href="/events">All events <x-icon name="arrow" size="16"/></a>@endunless</div>
                        <div class="panel">
                            @foreach($upcomingEvents as $event)
                                <article class="item-row"><div><h3>@if($preview ?? false){{ $event->title }}@else<a href="{{ $event->publicPath() }}">{{ $event->title }}</a>@endif</h3><p>{{ $event->localStart()->format('M j · g:i a T') }} · {{ $event->location->name }}</p></div></article>
                            @endforeach
                        </div>
                    </section>
                @endif
                @if($latestJobs->isNotEmpty())
                    <section class="homepage-section" aria-labelledby="jobs-title">
                        <div class="section-heading"><h2 id="jobs-title">Latest jobs</h2>@unless($preview ?? false)<a class="text-link" href="/jobs">All jobs <x-icon name="arrow" size="16"/></a>@endunless</div>
                        <div class="panel">
                            @foreach($latestJobs as $job)
                                <article class="item-row"><div><h3>@if($preview ?? false){{ $job->title }}@else<a href="/jobs/{{ $job->id }}">{{ $job->title }}</a>@endif</h3><p>{{ $job->branch->business->name }} · {{ $job->branch->location->name }}</p></div></article>
                            @endforeach
                        </div>
                    </section>
                @endif
            </div>
        @endif

        @unless($preview ?? false)
        <section class="network-strip" aria-label="Manitoba community network">
            <span class="location-icon"><x-icon name="building"/></span>
            @if($site->isNetwork())
                <div><h2>Visit BrandonMB</h2><p>Explore the dedicated portal for Brandon.</p></div>
                @php($brandon = \App\Models\Site::with('domain')->where('code', 'brandon')->where('status', 'active')->first())
                @if($brandon)<a class="text-link" href="{{ \App\Support\NetworkUrl::to($brandon) }}">Open BrandonMB <x-icon name="arrow" size="16"/></a>@endif
            @else
                <div><h2>Explore Manitoba</h2><p>Find other Manitoba communities on CanadaMB.</p></div>
                <a class="text-link" href="{{ \App\Support\NetworkUrl::to(\App\Support\NetworkUrl::main(), '/communities') }}">View communities <x-icon name="arrow" size="16"/></a>
            @endif
        </section>
        @endunless
    </div>
</x-layouts.site>
