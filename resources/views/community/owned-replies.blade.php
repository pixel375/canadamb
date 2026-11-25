<x-layouts.site title="My replies">
    <div class="container page-shell">
        <div class="page-intro"><div><a class="text-link" href="/account/discussions">My discussions</a><h1>My replies</h1><p>Manage your replies and check their moderation status.</p></div><a class="button button-outline" href="/community">Browse discussions</a></div>
        <nav class="seller-status-grid discussion-owner-status" aria-label="Reply status">
            @foreach(array_merge(['all'], $statuses) as $status)
                @php($params = array_filter(['status' => $status === 'all' ? null : $status, 'q' => $filters['q'] ?? null]))
                <a class="seller-status-tile" href="/account/replies{{ $params ? '?'.http_build_query($params) : '' }}" @if(($filters['status'] ?? 'all') === $status) aria-current="page" @endif><strong>{{ $status === 'all' ? $counts->sum() : ($counts[$status] ?? 0) }}</strong><span>{{ ucfirst($status) }}</span></a>
            @endforeach
        </nav>
        <form method="get" action="/account/replies" class="panel seller-listing-search">
            @if($filters['status'] ?? null)<input type="hidden" name="status" value="{{ $filters['status'] }}">@endif
            <label for="own-reply-q" class="sr-only">Search my replies</label><input id="own-reply-q" type="search" name="q" maxlength="100" value="{{ $filters['q'] ?? '' }}" placeholder="Search your replies"><button class="button button-primary">Search</button>
        </form>
        <section class="seller-listing-grid">
            @forelse($items as $item)
                <article class="panel seller-listing-card">
                    <div><span class="member-listing-status" data-status="{{ $item->status }}">{{ ucfirst($item->status) }}</span><h2>{{ $item->thread->status === 'approved' ? $item->thread->title : 'Discussion unavailable' }}</h2><p class="field-note">{{ $item->created_at->timezone('America/Winnipeg')->format('M j, Y · g:i a T') }}</p></div>
                    <p class="prose-text">{{ \Illuminate\Support\Str::limit($item->body, 300) }}</p>
                    @if($item->thread->status === 'approved')
                        @if($item->status === 'approved')<a class="text-link" href="/community/{{ $item->thread_id }}/replies/{{ $item->id }}">View published reply</a>@endif
                        @if(!$item->thread->locked && in_array($item->status, ['pending','approved','rejected'], true))
                            <a class="button button-outline" href="/account/replies/{{ $item->id }}/edit">Edit reply</a>
                            <details><summary>Withdraw reply</summary><form class="form-stack" method="post" action="/account/replies/{{ $item->id }}/withdraw">@csrf<input type="hidden" name="version" value="{{ $item->contentVersion() }}"><p class="field-note">This permanently hides this reply from public view.</p><button class="button button-outline">Confirm withdrawal</button></form></details>
                        @endif
                    @endif
                </article>
            @empty<div class="panel"><p>No replies match these filters.</p><a class="text-link" href="/account/replies">View all my replies</a></div>@endforelse
        </section>
        {{ $items->links() }}
    </div>
</x-layouts.site>
