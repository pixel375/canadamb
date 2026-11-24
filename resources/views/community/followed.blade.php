<x-layouts.site title="Following discussions">
    <div class="container page-shell discussion-followed-shell">
        <nav class="discussion-breadcrumb" aria-label="Following navigation">
            <a class="text-link" href="/community">Discussions</a>
            <x-icon name="chevron" size="14"/>
            <span>Following</span>
        </nav>
        <div class="page-intro discussion-followed-intro">
            <div>
                <p class="section-label">Your community</p>
                <h1>Following discussions</h1>
                <p>Keep track of conversations you've chosen to follow in this community.</p>
            </div>
            <a class="button button-outline" href="/account/community-notifications"><x-icon name="bell" size="17"/> Reply notifications</a>
        </div>
        <div class="discussion-followed-heading">
            <h2>My conversations</h2>
            <span class="discussion-count">{{ $threads->total() }} {{ \Illuminate\Support\Str::plural('discussion', $threads->total()) }}</span>
        </div>
        <section class="panel discussion-followed-list" aria-label="Followed discussion list">
            @forelse($threads as $thread)
                <article class="discussion-topic">
                    <div class="discussion-topic-meta">
                        <span>{{ \App\Support\Community::FORUMS[$thread->category] }}</span>
                        <span aria-hidden="true">·</span>
                        <span>{{ $thread->location?->name ?? 'Manitoba' }}</span>
                        @if($thread->locked)<span class="discussion-topic-pill">Locked</span>@endif
                    </div>
                    <h3><a href="/community/{{ $thread->id }}">{{ $thread->title }}</a></h3>
                    <p class="discussion-topic-excerpt">{{ \Illuminate\Support\Str::limit($thread->body, 170) }}</p>
                    <div class="discussion-topic-footer">
                        <span><x-icon name="messages" size="15"/> {{ $thread->replies_count }} {{ \Illuminate\Support\Str::plural('reply', $thread->replies_count) }}</span>
                        <span><x-icon name="clock" size="15"/> Active {{ $thread->last_activity_at?->diffForHumans() ?? 'recently' }}</span>
                    </div>
                </article>
            @empty
                <div class="discussion-notifications-empty">
                    <x-icon name="discussions" size="28"/>
                    <h2>You're not following any discussions here</h2>
                    <p>Open a discussion and choose Follow discussion to get notified about new approved replies.</p>
                    <a class="button button-primary" href="/community">Explore discussions</a>
                </div>
            @endforelse
        </section>
        {{ $threads->links() }}
    </div>
</x-layouts.site>
