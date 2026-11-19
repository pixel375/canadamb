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
        <a class="text-link mb-4" href="/events">Community events</a>
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
