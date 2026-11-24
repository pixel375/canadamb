<x-layouts.site title="My discussions">
    <div class="container page-shell">
        <div class="page-intro"><div><a class="text-link" href="/account/profile">My account</a><h1>My discussions</h1><p>Check your submitted topics and moderation status.</p></div><a class="button button-primary" href="/community/new">Start a discussion</a></div>
        <nav class="seller-status-grid discussion-owner-status" aria-label="Discussion status">
            @foreach(array_merge(['all'], $statuses) as $status)
                <?php $params = array_filter(['status' => $status === 'all' ? null : $status, 'q' => $filters['q'] ?? null]); ?>
                <a class="seller-status-tile" href="/account/discussions{{ $params ? '?'.http_build_query($params) : '' }}" @if(($filters['status'] ?? 'all') === $status) aria-current="page" @endif><strong>{{ $status === 'all' ? $counts->sum() : ($counts[$status] ?? 0) }}</strong><span>{{ ucfirst($status) }}</span></a>
            @endforeach
        </nav>
        <form method="get" action="/account/discussions" class="panel seller-listing-search">
            @if($filters['status'] ?? null)<input type="hidden" name="status" value="{{ $filters['status'] }}">@endif
            <label for="own-discussion-q" class="sr-only">Search my discussions</label><input id="own-discussion-q" type="search" name="q" maxlength="100" value="{{ $filters['q'] ?? '' }}" placeholder="Search your discussions"><button class="button button-primary">Search</button>
        </form>
        <p class="field-note seller-results-count">{{ $items->total() }} {{ \Illuminate\Support\Str::plural('discussion', $items->total()) }} found</p>
        <section class="seller-listing-grid">
            @forelse($items as $item)
                <article class="panel seller-listing-card"><div><span class="member-listing-status" data-status="{{ $item->status }}">{{ ucfirst($item->status) }}</span><h2>{{ $item->title }}</h2><p class="field-note">{{ $item->location?->name ?? 'Manitoba' }} · {{ \App\Support\Community::FORUMS[$item->category] }}</p></div><p class="prose-text">{{ \Illuminate\Support\Str::limit($item->body, 220) }}</p><p class="seller-lifecycle-note">{{ $item->status === 'pending' ? 'Awaiting review; not public.' : ($item->status === 'rejected' ? 'Not approved for publication.' : 'Published · '.$item->replies_count.' approved replies') }}</p>
                    @if($item->status === 'approved')<a class="button button-outline" href="/community/{{ $item->id }}">Open discussion</a>@endif
                </article>
            @empty<div class="panel market-results-empty"><h2>No discussions match these filters.</h2><a class="button button-outline" href="/account/discussions">View all my discussions</a></div>@endforelse
        </section>
        {{ $items->links() }}
    </div>
</x-layouts.site>
