<x-layouts.site :title="$thread->subject().' | Messages'">
    <div class="container page-shell message-shell">
        <a class="text-link" href="/account/messages">All messages</a>
        <header class="panel message-context">
            <p class="section-label">{{ \App\Support\ConversationAccess::TYPES[$thread->context_type] }} · {{ $thread->area()?->name }}</p>
            <h1>{{ $thread->subject() }}</h1>
            <p>Conversation with {{ $thread->peer($member)->name }}</p>
        </header>
        <div class="panel" id="message-history">
            <ol class="message-timeline">
                @forelse($messages->reverse() as $message)
                    <li class="message-entry {{ $message->sender_id === $member ? 'message-own' : '' }}">
                        <strong>{{ $message->sender->name }}</strong>
                        <time class="field-note" datetime="{{ $message->created_at->toAtomString() }}">{{ $message->created_at->timezone('America/Winnipeg')->format('M j, Y · g:i a T') }}</time>
                        @if($message->body !== '')<p class="prose-text">{{ $message->body }}</p>@endif
                        @if(!$blocked && $message->attachments->isNotEmpty())
                            <ul class="message-attachments">
                                @foreach($message->attachments as $file)
                                    @php($url = "/account/messages/$thread->id/attachments/$file->id")
                                    <li><a class="text-link" href="{{ $url }}">
                                        @if($file->mime_type === 'image/webp')
                                            <img src="{{ $url }}" alt="Photo attachment" loading="lazy">
                                            <span>Open photo</span>
                                        @else
                                            <span>Download {{ $file->original_name }}</span>
                                        @endif
                                    </a></li>
                                @endforeach
                            </ul>
                        @endif
                        @if($message->sender_id !== $member)
                            <details class="message-report"><summary>Report message</summary>
                                <form method="post" action="/account/messages/{{ $thread->id }}/report" class="form-stack">
                                    @csrf
                                    <input type="hidden" name="message_id" value="{{ $message->id }}">
                                    <label>Reason<textarea name="reason" minlength="8" maxlength="500" rows="2" required></textarea></label>
                                    <button class="button button-outline">Submit report</button>
                                </form>
                            </details>
                        @endif
                    </li>
                @empty
                    <li>No messages in this conversation.</li>
                @endforelse
            </ol>
            @php($messages->fragment('message-history'))
            @if($messages->previousPageUrl() || $messages->nextPageUrl())
                <nav class="inline-actions mt-3" aria-label="Message history pages">
                    @if($messages->previousPageUrl())<a class="button button-outline" href="{{ $messages->previousPageUrl() }}">Newer messages</a>@endif
                    @if($messages->nextPageUrl())<a class="button button-outline" href="{{ $messages->nextPageUrl() }}">Older messages</a>@endif
                </nav>
            @endif
        </div>
        @if($replyAllowed)
            <form method="post" action="/account/messages/{{ $thread->id }}" enctype="multipart/form-data" class="panel form-stack">
                @csrf
                <x-message-fields label="Reply"/>
                <div><button class="button button-primary">Send reply</button></div>
            </form>
        @elseif($blocked)
            <p class="notice">New messages and attachment access are blocked for this conversation.</p>
        @else
            <p class="notice">Replies are closed because the representative no longer has access.</p>
        @endif
        <div class="panel form-stack">
            <form method="post" action="/account/messages/{{ $thread->id }}/block">
                @csrf @if($myBlock)@method('DELETE')@endif
                <button class="button button-outline">{{ $myBlock ? 'Unblock member' : 'Block member' }}</button>
            </form>
            <details class="message-report"><summary>Report conversation</summary>
                <form method="post" action="/account/messages/{{ $thread->id }}/report" class="form-stack">
                    @csrf
                    <label>Reason<textarea name="reason" minlength="8" maxlength="500" rows="2" required></textarea></label>
                    <button class="button button-outline">Submit report</button>
                </form>
            </details>
        </div>
    </div>
</x-layouts.site>
