<x-layouts.site title="Discussion notifications">
    <div class="container page-shell narrow">
        <div class="page-intro"><div><h1>Discussion notifications</h1><p>Approved replies in discussions you follow.</p></div></div>
        <div class="panel">
            @forelse($notifications as $notification)
                <div class="item-row"><div><a class="text-link" href="/community/{{ $notification->thread_id }}">{{ $notification->title }}</a><p>{{ $notification->created_at }}</p></div>
                    @if(!$notification->read_at)
                        <form method="post" action="/account/community-notifications/{{ $notification->id }}/read">@csrf<button class="button button-outline">Mark as read</button></form>
                    @endif
                </div>
            @empty<p>No discussion notifications yet.</p>@endforelse
            {{ $notifications->links() }}
        </div>
    </div>
</x-layouts.site>
