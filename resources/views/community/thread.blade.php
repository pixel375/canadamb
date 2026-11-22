@php
    $viewer = auth()->user();
    $canParticipate = $viewer?->hasVerifiedEmail();
@endphp
<x-layouts.site :title="$item->title.' | Discussions'" :description="\Illuminate\Support\Str::limit(\Illuminate\Support\Str::squish($item->body), 155)" :canonical="$canonical">
    <div class="container page-shell narrow">
        <nav class="mb-4" aria-label="Breadcrumb"><a class="text-link" href="/discussions">Discussions</a></nav>
        <article class="panel discussion-post">
            <p class="section-label">{{ \App\Support\Community::FORUMS[$item->category] }} · {{ $item->location?->name ?? 'Manitoba-wide' }}@if($item->pinned) · Pinned @endif</p>
            <h1>{{ $item->title }}</h1>
            <p>
                @if($item->author)
                    <a class="discussion-member" href="/users/{{ $item->author->id }}">{{ $item->author->name }}</a>
                @else
                    Former member
                @endif
                · <time datetime="{{ $item->created_at->toIso8601String() }}">{{ $item->created_at->timezone('America/Winnipeg')->format('M j, Y · g:i a T') }}</time>
            </p>
            <p class="prose-text">{{ $item->body }}</p>
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
