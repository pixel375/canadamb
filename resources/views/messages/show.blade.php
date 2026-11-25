<x-layouts.site :title="$thread->title().' | Messages'">
    @php($messageReports = auth()->user()->hasVerifiedEmail() && \App\Support\ContentReports::enabled())
    <div class="container page-shell message-workspace">
        <a class="text-link mb-4" href="/account/messages"><x-icon name="messages" size="18"/> All messages</a>
        <div class="panel chat-panel" @if($messages->onFirstPage()) data-chat-sync="/account/messages/{{ $thread->id }}/sync" data-chat-cursor="{{ $messages->first()?->id ?? 0 }}" data-chat-token="{{ csrf_token() }}" data-chat-reportable="{{ $messageReports ? '1' : '0' }}" data-chat-conversation="{{ $thread->id }}" @endif>
            <header class="chat-heading">
                <span class="message-avatar" aria-hidden="true"><x-icon name="person" size="26"/></span>
                <div class="chat-heading-copy">
                    <p class="section-label">{{ $thread->kindLabel() }} conversation</p>
                    <h1>{{ $thread->buyer_id === auth()->id() ? $thread->seller->name : $thread->buyer->name }}</h1>
                    <p>{{ $thread->title() }}</p><a class="text-link" href="/users/{{ $thread->other(auth()->id()) }}">View member profile</a>
                </div>
                <details class="chat-actions">
                    <summary>Options <x-icon name="chevron-down" size="16"/></summary>
                    <div class="chat-actions-menu">
                    @if(\App\Support\MessagingExtensions::enabled())
                        <form method="post" action="/account/messages/{{ $thread->id }}/archive">@csrf
                            @if($thread->{$thread->archiveMarker(auth()->id())})@method('DELETE')@endif
                            <button class="button button-outline">{{ $thread->{$thread->archiveMarker(auth()->id())} ? 'Restore conversation' : 'Archive and mark read' }}</button>
                        </form>
                    @endif
                    <form method="post" action="/account/messages/{{ $thread->id }}/block">
                        @csrf @if($myBlock)@method('DELETE')@endif
                        <p class="field-note">{{ $myBlock ? 'Allow this member to contact you again.' : 'Blocking stops new messages between you and this member.' }}</p>
                        <button class="button button-outline">{{ $myBlock ? 'Unblock member' : 'Block member' }}</button>
                    </form><a class="text-link" href="/account/blocked-members">Manage blocked members</a>
                    </div>
                </details>
            </header>
            <nav class="chat-history-nav" aria-label="Conversation history">
                @if($messages->hasMorePages())<a class="text-link" href="{{ $messages->nextPageUrl() }}">Older messages</a>@endif
                @if(!$messages->onFirstPage())<a class="text-link" href="{{ $messages->previousPageUrl() }}">Newer messages</a><a class="text-link" href="/account/messages/{{ $thread->id }}">Latest messages</a>@endif
            </nav>
            <div class="chat-history" aria-label="Messages in chronological order" data-chat-history>
                @foreach($messages->reverse() as $message)
                    <article data-message-id="{{ $message->id }}" class="chat-message {{ $message->sender_id === auth()->id() ? 'is-own' : 'is-other' }}">
                        <div class="chat-message-meta">
                            <strong>{{ $message->sender_id === auth()->id() ? 'You' : $message->sender->name }}</strong>
                            <time datetime="{{ $message->created_at->toIso8601String() }}" title="{{ $message->created_at->timezone('America/Winnipeg')->format('M j, Y · g:i a') }}">{{ $message->created_at->diffForHumans() }}</time>
                        </div>
                        <p class="chat-bubble">{{ $message->body }}</p>
                        @if($message->attachment_exists)<a class="text-link" href="/account/messages/{{ $thread->id }}/attachments/{{ $message->id }}">Download photo attachment</a>@endif
                        @if($messageReports && $message->sender_id !== auth()->id())<a class="text-link field-note" href="/account/report/message/{{ $message->id }}">Report message</a>@if($message->attachment_exists)<a class="text-link field-note" href="/account/report/message-photo/{{ $message->id }}">Report photo</a>@endif
                        @endif
                    </article>
                @endforeach
            </div>
            @if($messages->onFirstPage())<button type="button" class="button button-outline chat-jump" data-chat-jump>Jump to latest message</button><p class="chat-sync-status field-note" data-chat-status role="status" aria-live="polite">Messages refresh automatically while this conversation is open.</p>@endif
            @if(!$blocked)
                <form method="post" action="/account/messages/{{ $thread->id }}" class="chat-composer form-stack" enctype="multipart/form-data">@csrf
                    <label>Reply<textarea data-character-count name="body" maxlength="2000" rows="3" required placeholder="Write a message…">{{ old('body') }}</textarea><small class="field-note" data-character-feedback></small></label>
                    @if(\App\Support\MessageAttachments::enabled())
                        <label>Optional photo<input type="file" name="photo" accept="image/jpeg,image/png,image/webp"><small class="field-note">One photo, up to 4 MB. Photos are resized and metadata removed; only conversation participants can download them. Do not send IDs or sensitive documents.</small></label>
                    @endif
                    <div class="chat-composer-footer"><small class="field-note">Keep personal and payment information private. <a class="text-link" href="/safety">Safety guidance</a></small><button class="button button-primary" data-chat-send>Send reply <x-icon name="arrow" size="17"/></button></div>
                </form>
            @else
                <p class="notice">New messages are blocked for this conversation.</p>
            @endif
        </div>
    </div>
</x-layouts.site>
