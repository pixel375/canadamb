<x-layouts.site title="Messages">
    <div class="container page-shell message-shell">
        <div class="page-intro">
            <div><h1>Messages</h1><p>{{ $totalUnread }} unread across this portal</p></div>
            <a class="text-link" href="/account/profile">My account</a>
        </div>
        <nav class="message-filters inline-actions" aria-label="Conversation types">
            @foreach(['' => 'All'] + $types as $key => $label)
                @php($params = array_filter(['type' => $key, 'q' => $filters['q'] ?? null], fn ($value) => $value !== null && $value !== ''))
                <a class="button {{ ($filters['type'] ?? '') === $key ? 'button-primary' : 'button-outline' }}"
                   href="/account/messages{{ $params ? '?'.http_build_query($params) : '' }}"
                   @if(($filters['type'] ?? '') === $key) aria-current="page" @endif>{{ $label }}</a>
            @endforeach
        </nav>
        <form class="panel form-stack message-search" method="get" action="/account/messages">
            @if(isset($filters['type']))<input type="hidden" name="type" value="{{ $filters['type'] }}">@endif
            <label>Search conversations<input type="search" name="q" value="{{ $filters['q'] ?? '' }}" maxlength="100" placeholder="Title, name or community"></label>
            <div class="inline-actions"><button class="button button-outline">Search</button>
                @if(isset($filters['q']))<a class="text-link" href="/account/messages{{ isset($filters['type']) ? '?type='.$filters['type'] : '' }}">Clear search</a>@endif
            </div>
        </form>
        <div class="panel">
            <ul class="message-thread-list">
                @forelse($threads as $thread)
                    @php($peer = $thread->peer($member))
                    <li class="message-thread">
                        <span class="message-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($peer->name, 0, 1)) }}</span>
                        <div>
                            <h2><a class="text-link" href="/account/messages/{{ $thread->id }}">{{ $thread->subject() }}</a></h2>
                            <p class="field-note">{{ $types[$thread->context_type] }}@if($category = $thread->categoryLabel()) · {{ $category }}@endif · {{ $thread->area()?->name }} · {{ $peer->name }}</p>
                            @if($thread->latestMessage)
                                <p class="field-note">Latest from {{ $thread->latestMessage->sender_id === $member ? 'you' : $peer->name }}</p>
                            @endif
                        </div>
                        <div class="message-thread-meta">
                            <time datetime="{{ $thread->updated_at->toAtomString() }}">{{ $thread->updated_at->diffForHumans() }}</time>
                            @if($thread->unread)<span class="notification-badge">{{ $thread->unread }} unread</span>@endif
                        </div>
                    </li>
                @empty
                    <li>{{ $filters ? 'No conversations match these filters.' : 'You have no conversations yet.' }}</li>
                @endforelse
            </ul>
        </div>
        {{ $threads->links() }}
    </div>
</x-layouts.site>
