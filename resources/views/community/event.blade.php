@php
    $eventData = [
        '@context' => 'https://schema.org', '@type' => 'Event', 'name' => $item->title,
        'startDate' => $item->localStart()->toAtomString(), 'endDate' => $item->localEnd()?->toAtomString(),
        'description' => $item->body, 'url' => $canonical, 'eventStatus' => 'https://schema.org/'.($item->status === 'cancelled' ? 'EventCancelled' : 'EventScheduled'),
        'location' => ['@type' => 'Place', 'name' => $item->venue, 'address' => ['@type' => 'PostalAddress', 'addressLocality' => $item->location->name, 'addressRegion' => 'MB', 'addressCountry' => 'CA']],
        'organizer' => ['@type' => 'Organization', 'name' => $item->organizer],
    ];
@endphp
<x-layouts.site :title="$item->title" :canonical="$canonical" :structured-data="array_filter($eventData)" :location="$item->location">
    <div class="container page-shell">
        <?php $site = request()->attributes->get('site'); ?>
        <nav class="discussion-breadcrumb" aria-label="Event navigation">
            @if(app(\App\Support\Community::class)->enabled($site, 'community'))
                <a class="text-link" href="{{ \App\Support\LocationScope::browseUrl($site, '/community', $item->location) }}">Discussions</a>
                <x-icon name="chevron" size="14"/>
            @endif
            <a class="text-link" href="{{ \App\Support\LocationScope::browseUrl($site, '/events', $item->location) }}">Events calendar</a>
        </nav>
        <header class="panel public-business-hero">
            <div class="event-date-tile event-hero-date"><strong>{{ $item->localStart()->format('j') }}</strong><span>{{ $item->localStart()->format('M Y') }}</span></div>
            <div class="public-business-heading"><p class="section-label">{{ \App\Support\Community::EVENTS[$item->category] }} · {{ $item->location->name }}</p><h1>{{ $item->title }}</h1><p>{{ $item->localStart()->format('l, F j, Y · g:i a T') }}</p></div>
        </header>
        @if($item->status === 'cancelled')<p class="notice notice-error"><strong>Cancelled</strong> · This event is no longer scheduled.</p>@endif
        <div class="public-profile-layout">
            <div class="public-profile-main"><section class="panel"><h2>About this event</h2><p class="prose-text">{{ $item->body }}</p></section>
                @if($related->isNotEmpty())
                    <section class="panel"><h2>More upcoming events in {{ $item->location->name }}</h2>
                        @foreach($related as $event)<article class="item-row"><div><h3><a class="text-link" href="/events/{{ $event->id }}">{{ $event->title }}</a></h3><p>{{ $event->localStart()->format('M j · g:i a T') }} · {{ $event->venue }}</p></div></article>@endforeach
                    </section>
                @endif
            </div>
            <aside class="public-profile-aside"><section class="panel form-stack">
                <h2>Event details</h2>@if($discussion)<a class="button button-outline" href="/community/{{ $discussion->id }}">Join event discussion</a>@endif<x-content-report-link type="event" :target="$item->id"/>
                <div class="business-contact-row"><x-icon name="calendar" size="20"/><div><h3>When</h3><p><time datetime="{{ $item->localStart()->toAtomString() }}">{{ $item->localStart()->format('l, F j, Y · g:i a T') }}</time>@if($item->localEnd()) to <time datetime="{{ $item->localEnd()->toAtomString() }}">{{ $item->localEnd()->format('M j · g:i a T') }}</time>@endif</p></div></div>
                <div class="business-contact-row"><x-icon name="pin" size="20"/><div><h3>Venue</h3><p>{{ $item->venue }} · {{ $item->location->name }}</p></div></div>
                <div class="business-contact-row"><x-icon name="person" size="20"/><div><h3>Organizer</h3><p>{{ $item->organizer }}</p></div></div>
                @if($item->status === 'approved')<a class="button button-outline" href="/events/{{ $item->id }}/calendar">Add to my calendar (.ics)</a>@endif
                @if($item->event_url && $item->status === 'approved')<a class="button button-primary" rel="nofollow ugc" href="{{ $item->event_url }}">Event or ticket information <x-icon name="arrow" size="16"/></a>@endif
                @if(auth()->id() === $item->author_id)<a class="button button-outline" href="/account/events">Manage my events</a>@endif
                <a class="text-link" href="{{ \App\Support\LocationScope::browseUrl($site, '/events', $item->location) }}">Find more local events <x-icon name="arrow" size="16"/></a>
            </section></aside>
        </div>
    </div>
</x-layouts.site>
