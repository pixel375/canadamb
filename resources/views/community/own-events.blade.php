<x-layouts.site title="My events">
    <div class="container page-shell narrow">
        <div class="page-intro"><div><h1>My events</h1><p>Review the status of your event submissions.</p></div><a class="button button-primary" href="/events/new">Submit an event</a></div>
        <section class="panel">
            @forelse($items as $item)
                <article class="item-row"><div><h2>{{ $item->title }}</h2><p>{{ ucfirst($item->status) }} · {{ $item->localStart()->format('M j, Y · g:i a') }}</p>
                    @if($item->cancellation_note)<p>Private cancellation note · {{ $item->cancellation_note }}</p>@endif
                </div>
                    @if(in_array($item->status, ['pending', 'approved'], true))
                        <form method="post" action="/account/events/{{ $item->id }}/cancel" class="form-stack">@csrf
                            <label>Private cancellation note<input name="cancellation_note" maxlength="300"></label><button class="button button-outline">Cancel event</button>
                        </form>
                    @endif
                </article>
            @empty<p>No events submitted yet.</p>@endforelse
            {{ $items->links() }}
        </section>
    </div>
</x-layouts.site>
