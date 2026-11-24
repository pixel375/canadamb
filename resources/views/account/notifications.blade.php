<x-layouts.site title="Notifications">
    <div class="container page-shell">
        <div class="page-intro">
            <div><h1>Notifications</h1><p>Keep up with your messages and approved replies to discussions you follow.</p></div>
        </div>
        <div class="activity-overview">
            <section class="activity-card" aria-labelledby="account-message-title">
                <span class="activity-icon"><x-icon name="messages" size="23"/></span>
                <div class="activity-card-body">
                    <h2 id="account-message-title">Messages</h2>
                    <p>{{ $counts['messages'] ? $counts['messages'].' unread '.\Illuminate\Support\Str::plural('message', $counts['messages']) : 'No unread private messages.' }}</p>
                    <a class="text-link" href="/account/messages">Open conversations <x-icon name="arrow" size="16"/></a>
                </div>
            </section>
            <section class="activity-card" aria-labelledby="account-discussions-title">
                <span class="activity-icon"><x-icon name="bell" size="23"/></span>
                <div class="activity-card-body">
                    <h2 id="account-discussions-title">Discussion replies</h2>
                    <p>
                        @if($discussionsEnabled)
                            {{ $counts['notifications'] ? $counts['notifications'].' unread '.\Illuminate\Support\Str::plural('reply notification', $counts['notifications']) : 'No unread discussion replies.' }}
                        @else
                            Discussion notifications are not available on this portal.
                        @endif
                    </p>
                    @if($discussionsEnabled)
                        <div class="activity-card-links">
                            <a class="text-link" href="/account/community-notifications">All discussion notifications <x-icon name="arrow" size="16"/></a>
                            <a class="text-link" href="/account/followed-discussions">Following discussions <x-icon name="arrow" size="16"/></a>
                        </div>
                    @endif
                </div>
            </section>
        </div>
        @if($discussionsEnabled)
            <section class="homepage-section activity-panel" aria-labelledby="recent-replies-title">
                <div class="section-heading">
                    <h2 id="recent-replies-title">Recent discussion activity</h2>
                    <a class="text-link" href="/account/community-notifications">View all <x-icon name="arrow" size="16"/></a>
                </div>
                <div class="panel">
                    @if($recent->isEmpty())
                        <p>No approved replies in discussions you follow yet.</p>
                    @else
                        @foreach($recent as $notification)
                        <div class="item-row">
                            <div>
                                <h3><a class="text-link" href="/community/{{ $notification->thread_id }}/replies/{{ $notification->reply_id }}">{{ $notification->title }}</a></h3>
                                <p>{{ \Illuminate\Support\Carbon::parse($notification->created_at)->diffForHumans() }}{{ $notification->read_at ? '' : ' · Unread' }}</p>
                            </div>
                            @if(! $notification->read_at)
                                <form method="post" action="/account/community-notifications/{{ $notification->id }}/read">
                                    @csrf
                                    <button class="button button-outline" type="submit">Mark as read</button>
                                </form>
                            @endif
                        </div>
                        @endforeach
                    @endif
                </div>
            </section>
        @endif
    </div>
</x-layouts.site>
