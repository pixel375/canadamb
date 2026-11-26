<x-layouts.site title="Messages">
    <div class="container page-shell message-workspace">
        <div class="page-intro"><div><h1>Messages</h1><p>Private conversations with members and businesses.</p></div><div class="inline-actions"><a class="text-link" href="/account/profile">My account</a><a class="button button-outline" href="/account/blocked-members">Blocked members</a></div></div>
        <?php
            $unreadOnly = ($filters['view'] ?? null) === 'unread';
            $archived = ($filters['view'] ?? null) === 'archived';
            $keyword = $filters['q'] ?? '';
            $kind = $filters['kind'] ?? '';
            $params = array_filter(array_diff_key($filters, ['view' => true]), fn ($value) => $value !== null && $value !== '');
            $urlFor = fn (array $changes = []) => '/account/messages'.(($query = http_build_query(array_filter(array_replace($params, $changes), fn ($value) => $value !== null && $value !== ''))) !== '' ? '?'.$query : '');
            $allUrl = $urlFor();
            $unreadUrl = $urlFor(['view' => 'unread']);
            $currentView = $filters['view'] ?? '';
        ?>
        <div class="panel message-inbox">
            <form class="message-search" method="get" action="/account/messages" role="search">
                @if($unreadOnly || $archived)<input type="hidden" name="view" value="{{ $archived ? 'archived' : 'unread' }}">@endif
                <input type="search" name="q" maxlength="100" value="{{ $keyword }}" placeholder="Search conversations or names" aria-label="Search conversations">
                @if(\App\Support\MessagingExtensions::enabled())<label class="message-kind-filter"><span>Conversation type</span><select name="kind"><option value="">All types</option>@foreach(['marketplace' => 'Marketplace', 'business' => 'Business inquiries', 'job' => 'Job inquiries'] as $key => $label)<option value="{{ $key }}" @selected($kind === $key)>{{ $label }}</option>@endforeach</select></label>@endif
                <label class="message-kind-filter"><span>My role</span><select name="role"><option value="">Any conversation</option><option value="sent" @selected(($filters['role'] ?? '') === 'sent')>I contacted someone</option><option value="received" @selected(($filters['role'] ?? '') === 'received')>Received inquiries</option></select></label>
                <label class="message-kind-filter"><span>Sort</span><select name="sort"><option value="recent" @selected(($filters['sort'] ?? 'recent') === 'recent')>Recent activity</option><option value="oldest" @selected(($filters['sort'] ?? '') === 'oldest')>Oldest activity</option></select></label>
                <button class="button button-primary" type="submit"><x-icon name="search" size="18"/> Search</button>
            </form>
            <nav class="message-filter-tabs" aria-label="Message filters">
                <a href="{{ $allUrl }}" @if(!$unreadOnly && !$archived) aria-current="page" @endif>All messages</a>
                <a href="{{ $unreadUrl }}" @if($unreadOnly) aria-current="page" @endif>Unread</a>
                @if(\App\Support\MessagingExtensions::enabled())<a href="{{ $urlFor(['view' => 'archived']) }}" @if($archived) aria-current="page" @endif>Archived</a>@endif
                @if($params)<a href="{{ '/account/messages'.($currentView ? '?view='.$currentView : '') }}">Clear search</a>@endif
            </nav>
            @if($readCutoff && !$archived)
                <details class="form-stack mb-4">
                    <summary>Mark all messages as read</summary>
                    <form method="post" action="/account/messages/read-all" class="form-stack mt-2">@csrf
                        <input type="hidden" name="cutoff" value="{{ $readCutoff }}">
                        <p class="field-note">Marks messages received up to the time this inbox loaded, across all active conversations and communities, even those outside the current search. No messages are deleted.</p>
                        <label class="checkbox-line"><input type="checkbox" name="confirm_read" value="1" required> Confirm marking messages read</label>
                        <button class="button button-outline">Mark all as read</button>
                    </form>
                </details>
            @endif
            @if(\App\Support\MessagingExtensions::enabled() && $threads->isNotEmpty())
                <details class="form-stack mb-4"><summary>{{ $archived ? 'Restore selected conversations' : 'Archive selected conversations' }}</summary>
                    <form id="conversation-batch" method="post" action="/account/messages/archive-selected" class="form-stack mt-2">@csrf
                        <input type="hidden" name="action" value="{{ $archived ? 'restore' : 'archive' }}">
                        <p class="field-note">Choose up to ten conversations below. Archiving marks them read for you only; newer messages return a conversation to the inbox.</p>
                        <label class="checkbox-line"><input type="checkbox" name="confirm_selection" value="1" required> Confirm selected conversations</label>
                        <button class="button button-outline">{{ $archived ? 'Restore selected' : 'Archive selected' }}</button>
                    </form>
                </details>
            @endif
            <div class="message-thread-list">
                @forelse($threads as $thread)
                    <?php $other = $thread->buyer_id === $member ? $thread->seller : $thread->buyer; ?>
                    @if(\App\Support\MessagingExtensions::enabled())
                        <label class="checkbox-line"><input form="conversation-batch" type="checkbox" name="conversations[]" value="{{ $thread->id }}"> Select conversation #{{ $thread->id }}</label>
                        <input form="conversation-batch" type="hidden" name="versions[{{ $thread->id }}]" value="{{ $thread->archiveVersion($member, (int) $thread->latestMessage?->id) }}">
                    @endif
                    <a class="message-thread @if($thread->unread) is-unread @endif" href="/account/messages/{{ $thread->id }}">
                        <span class="message-avatar" aria-hidden="true"><x-icon name="person" size="23"/></span>
                        <span class="message-thread-copy">
                            <span class="message-thread-name">{{ $other->name }} @if($thread->blocked_by_me)<small class="field-note"> · Blocked</small>@endif</span>
                            <strong>{{ $thread->title() }}</strong>
                            @if(\App\Support\MessagingExtensions::enabled())<span class="message-thread-kind">{{ $thread->kindLabel() }}</span>@endif
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
                        <h3>{{ ($params) ? 'No conversations match your search.' : ($archived ? 'You have no archived conversations.' : ($unreadOnly ? 'You have no unread messages.' : 'You have no conversations yet.')) }}</h3>
                        <p>{{ $params || $unreadOnly || $archived ? 'Browse all conversations to see your message history.' : 'When you contact a seller or receive an inquiry, your conversation will appear here.' }}</p>
                        @if($params || $unreadOnly || $archived)<a class="button button-outline" href="/account/messages">All conversations</a>@else<a class="button button-primary" href="/marketplace">Browse Marketplace</a>@endif
                    </div>
                @endforelse
            </div>
            {{ $threads->links() }}
        </div>
    </div>
</x-layouts.site>
