<x-layouts.site :title="$item->title" :canonical="$canonical" :structured-data="$item->status === 'approved' ? array_filter($item->structuredData($canonical)) : null">
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
            <x-content-report-form type="event" :target="$item->id" :author="$item->author_id" label="event"/>
        </article>
    </div>
</x-layouts.site>
