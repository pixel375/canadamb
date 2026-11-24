@php
    $eventData = [
        '@context' => 'https://schema.org', '@type' => 'Event', 'name' => $item->title,
        'startDate' => $item->localStart()->toAtomString(), 'endDate' => $item->localEnd()?->toAtomString(),
        'description' => $item->body, 'url' => $canonical, 'eventStatus' => 'https://schema.org/'.($item->status === 'cancelled' ? 'EventCancelled' : 'EventScheduled'),
        'location' => ['@type' => 'Place', 'name' => $item->venue, 'address' => ['@type' => 'PostalAddress', 'addressLocality' => $item->location->name, 'addressRegion' => 'MB', 'addressCountry' => 'CA']],
        'organizer' => ['@type' => 'Organization', 'name' => $item->organizer],
    ];
@endphp
<x-layouts.site :title="$item->title" :canonical="$canonical" :structured-data="array_filter($eventData)">
    <div class="container page-shell narrow">
        <nav class="discussion-breadcrumb" aria-label="Event navigation">
            @if(app(\App\Support\Community::class)->enabled(request()->attributes->get('site'), 'community'))
                <a class="text-link" href="/community">Discussions</a>
                <x-icon name="chevron" size="14"/>
            @endif
            <a class="text-link" href="/events">Events calendar</a>
        </nav>
        <article class="panel">
            <p class="section-label">{{ \App\Support\Community::EVENTS[$item->category] }} · {{ $item->location->name }}</p>
            <h1>{{ $item->title }}</h1>
            @if($item->status === 'cancelled')<p class="notice notice-error"><strong>Cancelled</strong></p>@endif
            <p><time datetime="{{ $item->localStart()->toAtomString() }}">{{ $item->localStart()->format('l, F j, Y · g:i a T') }}</time>@if($item->localEnd()) to <time datetime="{{ $item->localEnd()->toAtomString() }}">{{ $item->localEnd()->format('M j · g:i a T') }}</time>@endif</p>
            <p>{{ $item->venue }} · Organized by {{ $item->organizer }}</p>
            <p class="prose-text">{{ $item->body }}</p>
            @if($item->event_url && $item->status === 'approved')<a class="text-link" rel="nofollow ugc" href="{{ $item->event_url }}">Event or ticket information</a>@endif
        </article>
    </div>
</x-layouts.site>
