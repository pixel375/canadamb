<x-layouts.site title="Request an event listing">
    <div class="container page-shell narrow">
        <nav aria-label="Breadcrumb">@if($canRequest)<a class="text-link" href="/discussions">Discussions</a> / @endif<a class="text-link" href="/events">Events calendar</a></nav>
        <div class="page-intro"><div><h1>Request from a discussion</h1>
            <p>{{ $canRequest ? 'Open one of your approved discussions and choose Request event listing in its options menu.' : 'Event requests are unavailable on this site.' }}</p></div></div>
        @if($canRequest)<div class="panel inline-actions">
            <a class="button button-primary" href="/account/discussions">My Discussions</a>
            <a class="button button-outline" href="/discussions/new">Start a discussion</a>
        </div>@endif
    </div>
</x-layouts.site>
