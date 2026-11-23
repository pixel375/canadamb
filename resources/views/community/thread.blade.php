@php
    $viewer = auth()->user();
    $canParticipate = $viewer?->hasVerifiedEmail();
@endphp
<x-layouts.site :title="$item->title.' | Discussions'" :description="\Illuminate\Support\Str::limit(\Illuminate\Support\Str::squish($item->body), 155)" :canonical="$canonical" :structured-data="$eventData">
    <div class="container page-shell narrow">
        <nav class="mb-4" aria-label="Breadcrumb"><a class="text-link" href="/discussions">Discussions</a></nav>
        <article class="panel discussion-post">
            <p class="section-label">{{ \App\Support\Community::FORUMS[$item->category] }} · {{ $item->location?->name ?? 'Manitoba-wide' }}@if($item->pinned) · Pinned @endif</p>
            <div class="discussion-title-row">
                <h1>{{ $item->title }}</h1>
                @if($owner)
                    <details class="discussion-options">
                        <summary aria-label="Discussion options"><span aria-hidden="true">•••</span></summary>
                        <div class="discussion-options-links">
                            @if($canRequestEvent)<a href="/discussions/{{ $item->id }}/event">Request event listing</a>@endif
                            @if($ownedEvent)<a href="/account/events">Manage event · {{ $ownedEvent->status === 'pending' ? 'Pending review' : ucfirst($ownedEvent->status) }}</a>@endif
                            @unless($item->locked)<a href="/account/discussions/{{ $item->id }}/edit">Edit discussion</a>@endunless
                            <a href="/account/discussions/{{ $item->id }}/preview">Manage discussion</a>
                        </div>
                    </details>
                @endif
            </div>
            <p>
                @if($item->author)
                    <a class="discussion-member" href="/users/{{ $item->author->id }}">{{ $item->author->name }}</a>
                @else
                    Former member
                @endif
                · <time datetime="{{ $item->created_at->toIso8601String() }}">{{ $item->created_at->timezone('America/Winnipeg')->format('M j, Y · g:i a T') }}</time>
            </p>
            <p class="prose-text">{{ $item->body }}</p>
            @if($linkedEvent)
                <section class="linked-event-summary" aria-labelledby="linked-event-title">
                    <h2 id="linked-event-title">Event details</h2>
                    @if($linkedEvent->status === 'cancelled')<p class="notice notice-error"><strong>Cancelled</strong></p>@endif
                    <p><time datetime="{{ $linkedEvent->localStart()->toAtomString() }}">{{ $linkedEvent->localStart()->format('l, F j, Y · g:i a T') }}</time>
                        @if($linkedEvent->localEnd()) to <time datetime="{{ $linkedEvent->localEnd()->toAtomString() }}">{{ $linkedEvent->localEnd()->format('M j · g:i a T') }}</time>@endif
                    </p>
                    <p>{{ $linkedEvent->venue }} · {{ $linkedEvent->location->name }}</p>
                    <p>Organized by {{ $linkedEvent->organizer }} · {{ \App\Support\Community::EVENTS[$linkedEvent->category] ?? 'Event' }}</p>
                    @if($linkedEvent->event_url && $linkedEvent->status === 'approved')
                        <a class="text-link" rel="nofollow ugc" href="{{ $linkedEvent->event_url }}">Event or ticket information</a>
                    @endif
                    <x-content-report-form type="event" :target="$linkedEvent->id" :author="$linkedEvent->author_id" label="event"/>
                </section>
            @endif
            @if($canParticipate)
                <div class="inline-actions">
                    <form action="/discussions/{{ $item->id }}/follow" method="post">
                        @csrf
                        @if($following) @method('DELETE') @endif
                        <button class="button button-outline">{{ $following ? 'Stop following' : 'Follow discussion' }}</button>
                    </form>
                    @unless($item->locked)
                        <a class="text-link" href="#discussion-reply">Write a reply</a>
                    @endunless
                </div>
            @endif
            <x-content-report-form type="thread" :target="$item->id" :author="$item->author_id" label="discussion"/>
        </article>
        <section class="panel discussion-replies" id="discussion-replies" aria-labelledby="discussion-replies-heading">
            <h2 id="discussion-replies-heading">Replies ({{ $replies->total() }})</h2>
            @forelse($replies as $reply)
                <article id="reply-{{ $reply->id }}" class="discussion-reply" tabindex="-1">
                    <h3 data-quote-author>
                        @if($reply->author)
                            <a class="discussion-member" href="/users/{{ $reply->author->id }}">{{ $reply->author->name }}</a>
                        @else
                            Former member
                        @endif
                    </h3>
                    <p><time datetime="{{ $reply->created_at->toIso8601String() }}">{{ $reply->created_at->timezone('America/Winnipeg')->format('M j, Y · g:i a T') }}</time></p>
                    <p class="prose-text" data-quote-body>{{ $reply->body }}</p>
                    <div class="inline-actions">
                        <a class="text-link" href="/discussions/replies/{{ $reply->id }}">Link to reply</a>
                        @if($canParticipate && ! $item->locked)
                            <button class="text-link" type="button" data-discussion-quote hidden>Quote reply</button>
                        @endif
                    </div>
                    <x-content-report-form type="reply" :target="$reply->id" :author="$reply->author_id" label="reply"/>
                </article>
            @empty
                <p>No published replies yet.</p>
            @endforelse
            {{ $replies->fragment('discussion-replies')->links() }}
        </section>
        @if($item->locked)
            <p class="notice">This discussion is locked.</p>
        @endif
        @if($canParticipate && ! $item->locked)
            <form method="post" action="/discussions/{{ $item->id }}/replies" class="panel form-stack">
                @csrf
                <div>
                    <label for="discussion-reply">Write a reply
                        <textarea id="discussion-reply" name="body" required minlength="5" maxlength="3000" rows="4">{{ is_string(old('body')) ? old('body') : '' }}</textarea>
                    </label>
                    <p class="field-note" id="discussion-quote-status" role="status"></p>
                </div>
                <div><button class="button button-primary">Submit reply</button></div>
            </form>
        @elseif(! $canParticipate)
            <p class="mt-3">
                @if($viewer)
                    <a class="text-link" href="{{ \App\Support\NetworkUrl::to(\App\Support\NetworkUrl::main(), '/email/verify') }}">Verify your email</a>
                @else
                    <a class="text-link" href="/discussions/{{ $item->id }}/participate">Sign in</a>
                @endif
                {{ $item->locked ? 'to follow this discussion.' : 'to reply or follow.' }}
            </p>
        @endif
    </div>
</x-layouts.site>
