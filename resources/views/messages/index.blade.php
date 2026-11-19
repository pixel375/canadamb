<x-layouts.site title="Messages">
    <div class="container page-shell narrow">
        <div class="page-intro"><div><h1>Messages</h1><p>Private Marketplace conversations.</p></div><a class="text-link" href="/account/listings">My account</a></div>
        <div class="panel">
            @forelse($threads as $thread)
                <div class="item-row"><div><h3><a class="text-link" href="/account/messages/{{ $thread->id }}">{{ $thread->listing->title }}</a></h3><p>{{ $thread->buyer_id === $member ? $thread->seller->name : $thread->buyer->name }} · {{ $thread->updated_at->diffForHumans() }}</p></div>@if($thread->unread)<strong>{{ $thread->unread }} unread</strong>@endif</div>
            @empty<p>You have no conversations yet.</p>@endforelse
        </div>
        {{ $threads->links() }}
    </div>
</x-layouts.site>
