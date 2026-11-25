<x-layouts.site title="Messages">
    <div class="container page-shell message-workspace">
        <div class="page-intro"><div><h1>Messages</h1><p>Private Marketplace conversations.</p></div><div class="inline-actions"><a class="text-link" href="/account/profile">My account</a><a class="button button-outline" href="/account/blocked-members">Blocked members</a></div></div>
        <?php
            $unreadOnly = ($filters['view'] ?? null) === 'unread';
            $keyword = $filters['q'] ?? '';
            $allUrl = '/account/messages'.($keyword !== '' ? '?'.http_build_query(['q' => $keyword]) : '');
            $unreadUrl = '/account/messages?'.http_build_query(array_filter(['view' => 'unread', 'q' => $keyword], fn ($value) => $value !== ''));
        ?>
        <div class="panel message-inbox">
            <form class="message-search" method="get" action="/account/messages" role="search">
                @if($unreadOnly)<input type="hidden" name="view" value="unread">@endif
                <input type="search" name="q" maxlength="100" value="{{ $keyword }}" placeholder="Search listings or names" aria-label="Search conversations">
                <button class="button button-primary" type="submit"><x-icon name="search" size="18"/> Search</button>
            </form>
            <nav class="message-filter-tabs" aria-label="Message filters">
                <a href="{{ $allUrl }}" @if(!$unreadOnly) aria-current="page" @endif>All messages</a>
                <a href="{{ $unreadUrl }}" @if($unreadOnly) aria-current="page" @endif>Unread</a>
                @if($keyword !== '')<a href="{{ $unreadOnly ? '/account/messages?view=unread' : '/account/messages' }}">Clear search</a>@endif
            </nav>
            <div class="message-thread-list">
                @forelse($threads as $thread)
                    <?php $other = $thread->buyer_id === $member ? $thread->seller : $thread->buyer; ?>
                    <a class="message-thread @if($thread->unread) is-unread @endif" href="/account/messages/{{ $thread->id }}">
                        <span class="message-avatar" aria-hidden="true"><x-icon name="person" size="23"/></span>
                        <span class="message-thread-copy">
                            <span class="message-thread-name">{{ $other->name }} @if($thread->blocked_by_me)<small class="field-note"> · Blocked</small>@endif</span>
                            <strong>{{ $thread->listing->title }}</strong>
                            @if($thread->latestMessage)
                                <span class="message-thread-preview">{{ $thread->latestMessage->sender_id === $member ? 'You: ' : '' }}{{ \Illuminate\Support\Str::limit(\Illuminate\Support\Str::squish($thread->latestMessage->body), 100) }}</span>
                            @endif
                        </span>
                        <span class="message-thread-meta">
                            <time datetime="{{ $thread->updated_at->toIso8601String() }}">{{ $thread->updated_at->diffForHumans() }}</time>
                            @if($thread->unread)<span class="message-unread-count">{{ $thread->unread }} unread</span>@endif
                        </span>
                    </a>
                @empty
                    <div class="market-results-empty">
                        <span class="empty-icon"><x-icon name="messages" size="24"/></span>
                        <h3>{{ $keyword !== '' ? 'No conversations match your search.' : ($unreadOnly ? 'You have no unread messages.' : 'You have no conversations yet.') }}</h3>
                        <p>{{ $keyword !== '' || $unreadOnly ? 'Browse all conversations to see your message history.' : 'When you contact a seller or receive an inquiry, your conversation will appear here.' }}</p>
                        @if($keyword !== '' || $unreadOnly)<a class="button button-outline" href="/account/messages">All conversations</a>@else<a class="button button-primary" href="/marketplace">Browse Marketplace</a>@endif
                    </div>
                @endforelse
            </div>
            {{ $threads->links() }}
        </div>
    </div>
</x-layouts.site>
