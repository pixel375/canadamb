<x-layouts.site :title="$thread->listing->title.' | Messages'">
    <div class="container page-shell narrow">
        <div class="page-intro"><div><h1>{{ $thread->listing->title }}</h1><p>Conversation with {{ $thread->buyer_id === auth()->id() ? $thread->seller->name : $thread->buyer->name }}</p></div><a class="text-link" href="/account/messages">All messages</a></div>
        <div class="panel">
            @foreach($messages->reverse() as $message)
                <article class="item-row"><div><strong>{{ $message->sender->name }}</strong> <small>{{ $message->created_at->diffForHumans() }}</small><p class="prose-text">{{ $message->body }}</p></div></article>
            @endforeach
        </div>
        {{ $messages->links() }}
        @if(!$blocked)
            <form method="post" action="/account/messages/{{ $thread->id }}" class="panel form-stack">@csrf
                <label>Reply<textarea name="body" maxlength="2000" rows="3" required>{{ old('body') }}</textarea></label>
                <div><button class="button button-primary">Send reply</button></div>
            </form>
        @else<p class="notice">New messages are blocked for this conversation.</p>@endif
        <form class="mt-3" method="post" action="/account/messages/{{ $thread->id }}/block">
            @csrf @if($myBlock)@method('DELETE')@endif
            <button class="button button-outline">{{ $myBlock ? 'Unblock member' : 'Block member' }}</button>
        </form>
    </div>
</x-layouts.site>
