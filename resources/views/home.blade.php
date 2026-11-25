@php
    $site = request()->attributes->get('site');
    $homeModules = app(\App\Support\NetworkSettings::class)->forSite($site)['modules'];
    $enabled = app(\App\Support\Marketplace::class)->enabled($site);
    $place = $location->name ?? 'Manitoba';
    $scope = $site->isNetwork() && $location ? '?location='.$location->slug : '';
    $homeBrowseLink = fn (string $path) => \App\Support\LocationScope::browseUrl($site, $path, $location);
    $newsEnabled = $homeModules['news'] ?? false;
    $eventsEnabled = $homeModules['events'] ?? false;
    $eventPostUrl = ($homeModules['community'] ?? false) ? '/community/new?type=event' : '/events/new';
    if ($site->isNetwork() && $location) {
        $eventPostUrl .= (str_contains($eventPostUrl, '?') ? '&' : '?').'location='.rawurlencode($location->slug);
    }
    $hero = match ($site->code) { 'network' => 'manitoba', 'brandon' => 'brandon', default => null };
@endphp
<x-layouts.site :preview="$preview ?? false" :location="$location" main-class="home-main">
    <div class="container">
        @unless($preview ?? false)
            @if($site->isNetwork())
            <form class="location-bar" action="/" method="get">
                <span class="location-icon"><x-icon name="map"/></span>
                <div><label for="home-location">Explore by location</label><p>Browse listings, businesses, jobs and local activity in your part of Manitoba.</p></div>
                <select id="home-location" name="location" aria-label="Browsing location">
                    <option value="">All Manitoba</option>
                    @foreach($locations as $placeOption)
                        <option value="{{ $placeOption->slug }}" @selected($location?->id === $placeOption->id)>{{ $placeOption->name }}</option>
                    @endforeach
                </select>
                <button class="button button-outline" type="submit">Browse once</button>
            </form>
            <div class="home-preference-actions">
                <form method="post" action="/communities/preference">@csrf<input type="hidden" name="location" value="{{ $location?->slug }}"><button class="text-link" type="submit"><x-icon name="pin" size="16"/> {{ $location ? "Remember ".$location->name." on this browser" : "Use all Manitoba as my default" }}</button></form>
                <a class="text-link" href="/communities">Find another community</a>
            </div>
            @endif
        @endunless

        @php($businessesEnabled = app(\App\Support\BusinessDirectory::class)->enabled($site))
        @if($enabled || $businessesEnabled || ($homeModules['community'] ?? false) || ($homeModules['jobs'] ?? false) || $newsEnabled || $eventsEnabled)
            <section class="home-unified-search" aria-label="Search your community">
                <div class="home-search-heading">
                    <p class="section-label"><x-icon name="search" size="16"/> Find it locally</p>
                    <h2>Search {{ $place }}</h2>
                    <p>Find listings, businesses, conversations, jobs, news and events.</p>
                </div>
                <div class="home-search-tools">
                    <fieldset class="home-search-modes">
                        <legend class="sr-only">Choose what to search</legend>
                        @if($enabled)<label class="home-search-mode"><input type="radio" name="home-search-mode" value="marketplace" checked><span>Marketplace</span></label>@endif
                        @if($businessesEnabled)<label class="home-search-mode"><input type="radio" name="home-search-mode" value="businesses" @checked(! $enabled)><span>Businesses</span></label>@endif
                        @if($homeModules['community'] ?? false)<label class="home-search-mode"><input type="radio" name="home-search-mode" value="community" @checked(! $enabled && ! $businessesEnabled)><span>Discussions</span></label>@endif
                        @if($homeModules['jobs'] ?? false)<label class="home-search-mode"><input type="radio" name="home-search-mode" value="jobs" @checked(! $enabled && ! $businessesEnabled && ! ($homeModules['community'] ?? false))><span>Jobs</span></label>@endif
                        @if($newsEnabled)<label class="home-search-mode"><input type="radio" name="home-search-mode" value="news" @checked(!$enabled && !$businessesEnabled && !($homeModules['community'] ?? false) && !($homeModules['jobs'] ?? false))><span>News</span></label>@endif
                        @if($eventsEnabled)<label class="home-search-mode"><input type="radio" name="home-search-mode" value="events" @checked(!$enabled && !$businessesEnabled && !($homeModules['community'] ?? false) && !($homeModules['jobs'] ?? false) && !$newsEnabled)><span>Events</span></label>@endif
                    </fieldset>
                    <div class="home-search-forms">
                        @if($enabled)
                            <form class="home-search-form" data-home-search-section="marketplace" action="/marketplace" method="get" role="search">
                                <x-icon name="search" size="19"/>
                                <label class="sr-only" for="home-search-marketplace">Search Marketplace listings</label>
                                <input id="home-search-marketplace" type="search" name="q" maxlength="100" placeholder="Search listings, vehicles, furniture...">
                                @if($site->isNetwork() && $location)<input type="hidden" name="location" value="{{ $location->slug }}">@endif
                                <button class="button button-primary" type="submit">Search listings</button>
                            </form>
                        @endif
                        @if($businessesEnabled)
                            <form class="home-search-form" data-home-search-section="businesses" action="/businesses" method="get" role="search">
                                <x-icon name="search" size="19"/>
                                <label class="sr-only" for="home-search-businesses">Search local businesses</label>
                                <input id="home-search-businesses" type="search" name="q" maxlength="100" placeholder="Search shops, services, and businesses...">
                                @if($site->isNetwork() && $location)<input type="hidden" name="location" value="{{ $location->slug }}">@endif
                                <button class="button button-primary" type="submit">Find businesses</button>
                            </form>
                        @endif
                        @if($homeModules['community'] ?? false)
                            <form class="home-search-form" data-home-search-section="community" action="/community" method="get" role="search">
                                <x-icon name="search" size="19"/>
                                <label class="sr-only" for="home-search-community">Search discussion topics</label>
                                <input id="home-search-community" type="search" name="q" maxlength="100" placeholder="Search questions and conversations...">
                                @if($site->isNetwork() && $location)<input type="hidden" name="location" value="{{ $location->slug }}">@endif
                                <button class="button button-primary" type="submit">Find discussions</button>
                            </form>
                        @endif
                        @if($homeModules['jobs'] ?? false)
                            <form class="home-search-form" data-home-search-section="jobs" action="/jobs" method="get" role="search">
                                <x-icon name="search" size="19"/>
                                <label class="sr-only" for="home-search-jobs">Search jobs</label>
                                <input id="home-search-jobs" type="search" name="q" maxlength="100" placeholder="Search local jobs and opportunities...">
                                @if($site->isNetwork() && $location)<input type="hidden" name="location" value="{{ $location->name }}">@endif
                                <button class="button button-primary" type="submit">Find jobs</button>
                            </form>
                        @endif
                        @foreach(['news' => [$newsEnabled, 'Search headlines and notices...', 'Find news'], 'events' => [$eventsEnabled, 'Search events and venues...', 'Find events']] as $mode => [$available, $placeholder, $button])
                            @if($available)
                                <form class="home-search-form" data-home-search-section="{{ $mode }}" action="/{{ $mode }}" method="get" role="search">
                                    <x-icon name="search" size="19"/><label class="sr-only" for="home-search-{{ $mode }}">{{ $button }}</label>
                                    <input id="home-search-{{ $mode }}" type="search" name="q" maxlength="100" placeholder="{{ $placeholder }}">
                                    @if($site->isNetwork() && $location)<input type="hidden" name="location" value="{{ $location->slug }}">@endif
                                    <button class="button button-primary" type="submit">{{ $button }}</button>
                                </form>
                            @endif
                        @endforeach
                    </div>
                </div>
            </section>
        @endif
        <div class="home-lead-grid">
        <section class="hero{{ $hero ? ' hero-photo' : '' }}" aria-labelledby="hero-title">
            @if($hero)<img class="hero-image hero-{{ $hero }}" src="/images/{{ $hero }}.webp" alt="" fetchpriority="high">@endif
            <div class="hero-content">
                <p class="hero-location"><x-icon name="pin" size="16"/> {{ $location ? $location->name.', Manitoba' : 'Manitoba, Canada' }}</p>
                <h1 id="hero-title">{{ $site->tagline }}</h1>
                <p class="hero-description">{{ $site->description }}</p>
                @unless($preview ?? false)
                    <div class="hero-actions">
                        @if($enabled)<a class="button button-primary" href="/marketplace{{ $scope }}">Browse Marketplace <x-icon name="arrow" size="18"/></a>@endif
                        @if($site->isNetwork())<a class="button button-hero" href="/communities">Explore communities</a>
                        @elseif($enabled)<a class="button button-hero" href="{{ $homeBrowseLink('/marketplace/new') }}">Post a listing</a>@endif
                    </div>
                @endunless
            </div>
            @if($hero)<a class="hero-credit" href="/photo-credits">{{ $hero === 'brandon' ? 'Brandon from North Hill' : 'Canola fields in Manitoba' }}</a>@endif
        </section>
        <aside class="home-explore-panel" aria-labelledby="home-explore-title">
            <div class="home-explore-heading">
                <p class="section-label">Your local hub</p>
                <h2 id="home-explore-title">Explore {{ $place }}</h2>
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
                @if($enabled)
                    <a href="/real-estate{{ $scope }}" class="home-explore-link">
                        <span class="home-explore-icon"><x-icon name="house" size="23"/></span>
                        <span><strong>Real Estate</strong><small>Homes, rentals and land · Listings coming soon</small></span>
                        <x-icon name="arrow" size="17"/>
                    </a>
                @endif
                @if(app(\App\Support\BusinessDirectory::class)->enabled($site))
                    <a href="{{ $homeBrowseLink('/businesses') }}" class="home-explore-link">
                        <span class="home-explore-icon"><x-icon name="building" size="23"/></span>
                        <span><strong>Local businesses</strong><small>Shops and services nearby</small></span>
                        <x-icon name="arrow" size="17"/>
                    </a>
                @endif
                @if($homeModules['community'] ?? false)
                    <a href="{{ $homeBrowseLink('/community') }}" class="home-explore-link">
                        <span class="home-explore-icon"><x-icon name="discussions" size="23"/></span>
                        <span><strong>Discussions</strong><small>Connect with your neighbours</small></span>
                        <x-icon name="arrow" size="17"/>
                    </a>
                @endif
                @if($homeModules['events'] ?? false)
                    <a href="{{ $homeBrowseLink('/events') }}" class="home-explore-link">
                        <span class="home-explore-icon"><x-icon name="calendar" size="23"/></span>
                        <span><strong>Events</strong><small>See what's happening locally</small></span>
                        <x-icon name="arrow" size="17"/>
                    </a>
                @endif
                @if($homeModules['jobs'] ?? false)
                    <a href="{{ $homeBrowseLink('/jobs') }}" class="home-explore-link">
                        <span class="home-explore-icon"><x-icon name="briefcase" size="23"/></span>
                        <span><strong>Jobs</strong><small>Find opportunities in your area</small></span>
                        <x-icon name="arrow" size="17"/>
                    </a>
                @endif
                @if($site->isNetwork())
                    <a href="/communities" class="home-explore-link">
                        <span class="home-explore-icon"><x-icon name="map" size="23"/></span>
                        <span><strong>Communities</strong><small>Explore cities and towns</small></span>
                        <x-icon name="arrow" size="17"/>
                    </a>
                @endif
                @if($newsEnabled)
                    <a href="{{ $homeBrowseLink('/news') }}" class="home-explore-link">
                        <span class="home-explore-icon"><x-icon name="news" size="23"/></span>
                        <span><strong>Local news</strong><small>What's happening nearby</small></span>
                        <x-icon name="arrow" size="17"/>
                    </a>
                @endif
            </div>
        </aside>
        </div>

        @if($newsEnabled)
            <section class="homepage-section" aria-labelledby="news-title">
                <div class="section-heading"><h2 id="news-title">Latest news</h2>@unless($preview ?? false)<a class="text-link" href="{{ $homeBrowseLink('/news') }}">All news <x-icon name="arrow" size="16"/></a>@endunless</div>
                <div class="home-news-grid">
                    @forelse($articles as $article)
                                                <article class="panel home-news-card"><p class="section-label">{{ \App\Support\News::CATEGORIES[$article->category] }} · {{ $article->location?->name ?? 'Manitoba' }}</p><h3>@if($preview ?? false){{ $article->title }}@else<a href="/news/{{ $article->id }}">{{ $article->title }}</a>@endif</h3><p>{{ \Illuminate\Support\Str::limit(\Illuminate\Support\Str::squish($article->body), 150) }}</p><p class="field-note">{{ $article->source->name }} · {{ $article->published_at->timezone('America/Winnipeg')->format('M j, Y') }}</p></article>
                    @empty
                        <div class="panel home-news-empty"><x-icon name="news" size="26"/><div><h3>No published news here yet</h3><p>Check the local news section for source-attributed reports and public notices.</p>@unless($preview ?? false)<a class="text-link" href="{{ $homeBrowseLink('/news') }}">Browse news <x-icon name="arrow" size="16"/></a>@endunless</div></div>
                    @endforelse
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
                    <div><h3>No active listings yet</h3><p>New listings will appear here after review.</p>@unless($preview ?? false)<a class="text-link home-empty-link" href="/marketplace{{ $scope }}">Browse categories <x-icon name="arrow" size="15"/></a>@endunless</div>
                    @unless($preview ?? false)<a class="button button-outline" href="{{ $homeBrowseLink('/marketplace/new') }}"><x-icon name="plus" size="16"/> Post a listing</a>@endunless
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
                    @unless($preview ?? false)<a class="text-link" href="{{ $homeBrowseLink('/business-updates') }}">View all updates <x-icon name="arrow" size="16"/></a>@endunless
                </div>
                <div class="panel">
                    @forelse($businessUpdates as $update)
                        <article class="item-row">
                            <div><h3>@if($preview ?? false){{ $update->title }}@else<a href="/businesses/{{ $update->branch->business->slug }}">{{ $update->title }}</a>@endif</h3><p>{{ $update->branch->business->name }} · {{ $update->branch->location->name }}</p></div>
                            <time datetime="{{ $update->published_at->toDateString() }}">{{ $update->published_at->format('M j, Y') }}</time>
                        </article>
                    @empty
                        <div class="home-business-empty">
                            <span class="home-business-empty-icon" aria-hidden="true"><x-icon name="building" size="23"/></span>
                            <div>
                                <h3>Discover local businesses</h3>
                                <p>There are no published updates yet. Explore shops and services serving {{ $place }}.</p>
                                @unless($preview ?? false)<a class="text-link home-empty-link" href="{{ $homeBrowseLink('/businesses') }}">Browse the directory <x-icon name="arrow" size="15"/></a>@endunless
                            </div>
                        </div>
                    @endforelse
                </div>
            </section>
        @endif

        </div>

        @if(($homeModules['community'] ?? false) || ($homeModules['events'] ?? false) || ($homeModules['jobs'] ?? false))
            <section class="home-community-section" aria-labelledby="home-community-title">
                <div class="home-community-heading">
                    <div>
                        <p class="section-label">Community activity</p>
                        <h2 id="home-community-title">Around {{ $place }}</h2>
                        <p>Conversations, local happenings, and opportunities in your community.</p>
                    </div>
                    @unless($preview ?? false)
                        @if($homeModules['community'] ?? false)<a class="text-link" href="{{ $homeBrowseLink('/community') }}">Explore discussions <x-icon name="arrow" size="16"/></a>@endif
                    @endunless
                </div>
                <div class="community-activity home-community-grid">
                    @if($homeModules['community'] ?? false)
                        <section class="home-community-card" aria-labelledby="home-discussions-title">
                            <header class="home-community-card-heading">
                                <span class="home-community-card-icon" aria-hidden="true"><x-icon name="discussions" size="22"/></span>
                                <div><p class="section-label">Local conversations</p><h3 id="home-discussions-title">Discussions</h3></div>
                            </header>
                            <div class="home-community-card-content">
                                @forelse($recentDiscussions as $thread)
                                    <article class="home-community-item">
                                        <p class="home-community-item-eyebrow">{{ \App\Support\Community::FORUMS[$thread->category] ?? 'Discussion' }} · {{ $thread->location?->name ?? 'Manitoba' }}</p>
                                        <h4>@if($preview ?? false){{ $thread->title }}@else<a href="/community/{{ $thread->id }}">{{ $thread->title }}</a>@endif</h4>
                                        <p>{{ $thread->replies_count }} {{ \Illuminate\Support\Str::plural('reply', $thread->replies_count) }} · {{ \Illuminate\Support\Str::limit($thread->body, 88) }}</p>
                                    </article>
                                @empty
                                    <div class="home-community-empty">
                                        <h4>Start a local conversation</h4>
                                        <p>Ask a question, share a recommendation, or connect with neighbours.</p>
                                    </div>
                                @endforelse
                            </div>
                            @unless($preview ?? false)
                                <footer class="home-community-card-footer"><a href="{{ $recentDiscussions->isEmpty() ? $homeBrowseLink('/community/new') : $homeBrowseLink('/community') }}">{{ $recentDiscussions->isEmpty() ? 'Start a discussion' : 'Browse all discussions' }} <x-icon name="arrow" size="16"/></a></footer>
                            @endunless
                        </section>
                    @endif
                    @if($homeModules['events'] ?? false)
                        <section class="home-community-card" aria-labelledby="home-events-title">
                            <header class="home-community-card-heading">
                                <span class="home-community-card-icon" aria-hidden="true"><x-icon name="calendar" size="22"/></span>
                                <div><p class="section-label">What's happening</p><h3 id="home-events-title">Upcoming events</h3></div>
                            </header>
                            <div class="home-community-card-content">
                                @forelse($upcomingEvents as $event)
                                    <article class="home-community-item home-community-event">
                                        <time class="home-community-date" datetime="{{ $event->localStart()->toDateString() }}">
                                            <strong>{{ $event->localStart()->format('j') }}</strong><small>{{ $event->localStart()->format('M') }}</small>
                                        </time>
                                        <div>
                                            <h4>@if($preview ?? false){{ $event->title }}@else<a href="/events/{{ $event->id }}">{{ $event->title }}</a>@endif</h4>
                                            <p>{{ $event->localStart()->format('g:i a') }} · {{ $event->location->name }}</p>
                                        </div>
                                    </article>
                                @empty
                                    <div class="home-community-empty">
                                        <h4>Have an event to share?</h4>
                                        <p>Local events appear in the community calendar after review.</p>
                                    </div>
                                @endforelse
                            </div>
                            @unless($preview ?? false)
                                <footer class="home-community-card-footer"><a href="{{ $upcomingEvents->isEmpty() ? $eventPostUrl : $homeBrowseLink('/events') }}">{{ $upcomingEvents->isEmpty() ? 'Submit an event' : 'View events calendar' }} <x-icon name="arrow" size="16"/></a></footer>
                            @endunless
                        </section>
                    @endif
                    @if($homeModules['jobs'] ?? false)
                        <section class="home-community-card" aria-labelledby="home-jobs-title">
                            <header class="home-community-card-heading">
                                <span class="home-community-card-icon" aria-hidden="true"><x-icon name="briefcase" size="22"/></span>
                                <div><p class="section-label">Local opportunities</p><h3 id="home-jobs-title">Jobs</h3></div>
                            </header>
                            <div class="home-community-card-content">
                                @forelse($latestJobs as $job)
                                    <article class="home-community-item">
                                        <p class="home-community-item-eyebrow">{{ $job->branch->location->name }}</p>
                                        <h4>@if($preview ?? false){{ $job->title }}@else<a href="/jobs/{{ $job->id }}">{{ $job->title }}</a>@endif</h4>
                                        <p>{{ $job->branch->business->name }}</p>
                                    </article>
                                @empty
                                    <div class="home-community-empty">
                                        <h4>Find work nearby</h4>
                                        <p>Browse local opportunities and discover employers in {{ $place }}.</p>
                                    </div>
                                @endforelse
                            </div>
                            @unless($preview ?? false)
                                <footer class="home-community-card-footer"><a href="{{ $homeBrowseLink('/jobs') }}">Browse local jobs <x-icon name="arrow" size="16"/></a></footer>
                            @endunless
                        </section>
                    @endif
                </div>
            </section>
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
