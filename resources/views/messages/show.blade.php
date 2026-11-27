<x-layouts.site :title="$thread->title().' | Messages'" main-class="chat-main" :chat="true">
    @php($messageReports = auth()->user()->hasVerifiedEmail() && \App\Support\ContentReports::enabled())
    <div class="container message-workspace chat-workspace">
        <div class="panel chat-panel" @if($latestPage) data-chat-sync="/account/messages/{{ $thread->id }}/sync" data-chat-cursor="{{ $messages->first()?->id ?? 0 }}" data-chat-token="{{ csrf_token() }}" data-chat-reportable="{{ $messageReports ? '1' : '0' }}" data-chat-conversation="{{ $thread->id }}" @endif>
            <header class="chat-heading">
                <a class="chat-back" href="/account/messages" aria-label="Back to messages"><x-icon name="arrow-left" size="23"/></a>
                <a class="chat-member" href="/users/{{ $thread->other(auth()->id()) }}">
                    <span class="message-avatar" aria-hidden="true"><x-icon name="person" size="24"/></span>
                    <span class="chat-heading-copy"><strong>{{ $thread->buyer_id === auth()->id() ? $thread->seller->name : $thread->buyer->name }}</strong><small @if($thread->direct_kind === 'official') class="official-message-label" @endif>{{ $thread->direct_kind ? $thread->kindLabel() : $thread->title() }}</small></span>
                </a>
                <details class="chat-actions">
                    <summary aria-label="Conversation options"><x-icon name="more-vertical" size="22"/></summary>
                    <div class="chat-actions-menu">
                    @if(\App\Support\MessagingExtensions::enabled())
                        <form method="post" action="/account/messages/{{ $thread->id }}/archive">@csrf
                            @if($thread->{$thread->archiveMarker(auth()->id())})@method('DELETE')@endif
                            <button class="button button-outline">{{ $thread->{$thread->archiveMarker(auth()->id())} ? 'Restore conversation' : 'Archive and mark read' }}</button>
                        </form>
                    @endif
                    <form method="post" action="/account/messages/{{ $thread->id }}/block">
                        @csrf @if($myBlock)@method('DELETE')@endif
                        <button class="button button-outline">{{ $myBlock ? 'Unblock member' : 'Block member' }}</button>
                    </form>
                    @if($reportMessage)<a class="text-link" href="/account/report/message/{{ $reportMessage }}">Report chat</a>
                    @else<a class="text-link" href="/safety">Safety and reporting</a>@endif
                    </div>
                </details>
            </header>
            <div class="chat-history" aria-label="Messages in chronological order" data-chat-history tabindex="0">
                <nav class="chat-history-nav" aria-label="Conversation history">
                    @if($messages->nextPageUrl())<a class="text-link" data-chat-older href="{{ $messages->nextPageUrl() }}">Older messages</a>@endif
                    @if(!$latestPage)@if($messages->previousPageUrl())<a class="text-link" href="{{ $messages->previousPageUrl() }}">Newer messages</a>@endif<a class="text-link" href="/account/messages/{{ $thread->id }}">Latest messages</a>@endif
                </nav>
                @if($context)<a class="chat-context-card" href="{{ $context['url'] }}">
                    @if($context['thumbnail'])<img src="{{ $context['thumbnail'] }}" alt="">@endif
                    <span><small>{{ $thread->kindLabel() }}</small><strong>{{ $context['title'] }}</strong></span><x-icon name="arrow" size="18"/>
                </a>@endif
                @forelse($messages->reverse() as $message)
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
                @empty<p class="field-note">No messages yet.</p>@endforelse
            </div>
            @if($latestPage)<div class="chat-update-bar"><p class="chat-sync-status field-note" data-chat-status role="status" aria-live="polite"></p><button class="button button-outline chat-latest-button" type="button" data-chat-latest hidden>Jump to latest</button></div>@endif
            @if(!$blocked)
                <form method="post" action="/account/messages/{{ $thread->id }}" class="chat-composer form-stack" enctype="multipart/form-data">@csrf
                    <label><span class="sr-only">Reply</span><textarea data-character-count name="body" maxlength="2000" rows="2" required placeholder="Write a message…">{{ old('body') }}</textarea><small class="field-note" data-character-feedback></small></label>
                    @if(\App\Support\MessageAttachments::enabled())
                        <details class="chat-attachment-picker" @if($errors->has('photo')) open @endif><summary>Attach a photo</summary><label><span class="sr-only">Photo attachment</span><input type="file" name="photo" accept="image/jpeg,image/png,image/webp"><small class="field-note">One photo, up to 4 MB. Do not send sensitive documents.</small></label></details>
                    @endif
                    <div class="chat-composer-footer"><small class="field-note"><a class="text-link" href="/safety">Safety guidance</a> · Keep payment details private.</small><button class="button button-primary" data-chat-send>Send reply <x-icon name="arrow" size="17"/></button></div>
                </form>
            @else
                <p class="notice">New messages are blocked for this conversation.</p>
            @endif
        </div>
    </div>
</x-layouts.site>
