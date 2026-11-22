<x-layouts.site :title="$item->title" :canonical="$canonical">
    <div class="container page-shell narrow">
        <a class="text-link mb-4" href="/community">Discussions</a>
        <article class="panel">
            <p class="section-label">{{ \App\Support\Community::FORUMS[$item->category] }} · {{ $item->location?->name ?? 'Manitoba' }}</p>
            <h1>{{ $item->title }}</h1>
            <p>@if($item->author)<a class="text-link" href="/users/{{ $item->author->id }}">{{ $item->author->name }}</a>@else Former member @endif
                · <time datetime="{{ $item->created_at->toIso8601String() }}">{{ $item->created_at->timezone('America/Winnipeg')->format('M j, Y · g:i a T') }}</time></p>
            <p class="prose-text">{{ $item->body }}</p>
            @auth
                <form action="/community/{{ $item->id }}/follow" method="post">@csrf @if($following) @method('DELETE') @endif
                    <button class="button button-outline">{{ $following ? 'Stop following' : 'Follow discussion' }}</button>
                </form>
            @endauth
            <x-content-report-form type="thread" :target="$item->id" :author="$item->author_id" label="discussion"/>
        </article>
        <section class="panel">
            <h2>Replies</h2>
            @forelse($replies as $reply)
                <article id="reply-{{ $reply->id }}" class="item-row"><div>
                    <h3>@if($reply->author)<a href="/users/{{ $reply->author->id }}">{{ $reply->author->name }}</a>@else Former member @endif</h3>
                    <p><time datetime="{{ $reply->created_at->toIso8601String() }}">{{ $reply->created_at->timezone('America/Winnipeg')->format('M j, Y · g:i a T') }}</time></p>
                    <p class="prose-text">{{ $reply->body }}</p>
                    <x-content-report-form type="reply" :target="$reply->id" :author="$reply->author_id" label="reply"/>
                </div></article>
            @empty<p>No published replies yet.</p>@endforelse
            {{ $replies->links() }}
        </section>
        @if($item->locked)
            <p class="notice">This discussion is locked.</p>
        @elseif(auth()->check())
            <form method="post" action="/community/{{ $item->id }}/replies" class="panel form-stack">@csrf
                <label>Reply<textarea name="body" required minlength="5" maxlength="3000" rows="4">{{ old('body') }}</textarea></label>
                <div><button class="button button-primary">Submit reply</button></div>
            </form>
        @else
            <p class="mt-3"><a href="/login" class="text-link">Sign in to reply or follow</a></p>
        @endif
    </div>
</x-layouts.site>
