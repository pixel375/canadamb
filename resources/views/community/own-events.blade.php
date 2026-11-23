<x-layouts.site title="My events">
    <div class="container page-shell narrow">
        <div class="page-intro"><div><h1>My events</h1><p>View the status of your event requests and existing events.</p></div><a class="button button-outline" href="/account/discussions">My Discussions</a></div>
        <section class="panel form-stack">
            @forelse($items as $item)
                <article class="form-stack">
                    <div><h2>{{ $item->thread?->title ?? $item->title }}</h2>
                        <p>{{ $item->status === 'pending' ? 'Pending review' : ucfirst($item->status) }} · {{ $item->location->name }} · {{ $item->localStart()->format('M j, Y · g:i a T') }}</p>
                        @if(filled($reasons[$item->id] ?? null)) <p class="prose-text">Moderation reason · {{ $reasons[$item->id] }}</p> @endif
                        @if($item->cancellation_note)<p class="prose-text">Private cancellation note · {{ $item->cancellation_note }}</p>@endif
                    </div>
                    <div class="inline-actions">
                        @if($item->thread)
                            <a class="text-link" href="/account/discussions/{{ $item->thread_id }}/preview">Manage discussion</a>
                            @if($editingEnabled && \App\Support\LinkedEvents::matches($item, $item->thread))
                                <a class="button button-outline" href="/account/events/{{ $item->id }}/edit">{{ in_array($item->status, ['rejected', 'cancelled', 'withdrawn'], true) ? 'Revise and resubmit' : 'Edit event request' }}</a>
                            @elseif($editingEnabled)
                                <p class="field-note">The source discussion must be approved and unlocked to edit the event.</p>
                            @endif
                        @elseif(in_array($item->status, ['approved', 'cancelled'], true))
                            <a class="text-link" href="{{ $item->publicPath() }}">View event</a>
                        @endif
                    </div>
                    @if(in_array($item->status, ['pending', 'approved'], true))
                        <form method="post" action="/account/events/{{ $item->id }}/cancel" class="form-stack">
                            @csrf
                            @if($item->thread)
                                <input type="hidden" name="version" value="{{ \App\Support\ModerationDecision::version('event', $item) }}">
                                <input type="hidden" name="thread_version" value="{{ \App\Support\ModerationDecision::version('thread', $item->thread) }}">
                            @endif
                            <label>Private cancellation note<input name="cancellation_note" maxlength="300"></label>
                            <div><button class="button button-outline">Cancel event</button></div>
                        </form>
                    @endif
                </article>
            @empty<p>No events submitted yet. Request a listing from one of your approved discussions.</p>@endforelse
            {{ $items->links() }}
        </section>
    </div>
</x-layouts.site>
