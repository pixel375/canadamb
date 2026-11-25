<x-layouts.site title="Discussion notifications">
    <div class="container page-shell discussion-notification-shell">
        <nav class="discussion-breadcrumb" aria-label="Notification navigation">
            <a class="text-link" href="/account/notifications">Notifications</a>
            <x-icon name="chevron" size="14"/>
            <span>Discussion replies</span>
        </nav>
        <div class="page-intro discussion-notifications-intro">
            <div>
                <p class="section-label">Your conversations</p>
                <h1>Discussion notifications</h1>
                <p>New approved replies in discussions you follow, visible on this community.</p>
            </div>
            <a class="button button-outline" href="/account/followed-discussions"><x-icon name="bell" size="17"/> Following</a>
        </div>
        <div class="discussion-notification-tools">
            <nav class="discussion-notification-filters" aria-label="Filter discussion notifications">
                <a href="/account/community-notifications" @if($view === 'all') aria-current="page" @endif>All activity</a>
                <a href="/account/community-notifications?view=unread" @if($view === 'unread') aria-current="page" @endif>Unread <span>{{ $unreadCount }}</span></a>
            </nav>
            @if($unreadCount > 0)
                <form action="/account/community-notifications/read-all" method="post">
                    @csrf
                    <input type="hidden" name="cutoff" value="{{ $readCutoff }}">
                    <button type="submit" class="button button-outline"><x-icon name="check" size="16"/> Mark all as read</button>
                    <p class="field-note">Only notifications received before this page loaded.</p>
                </form>
            @endif
        </div>
        <section class="panel discussion-notification-list" aria-label="Discussion notification list">
            @forelse($notifications as $notification)
                <article class="discussion-notification-row{{ $notification->read_at ? '' : ' is-unread' }}">
                    <span class="discussion-notification-indicator" aria-hidden="true"></span>
                    <div class="discussion-notification-body">
                        <p class="section-label">{{ $notification->read_at ? 'Read reply' : 'New reply' }}</p>
                        <h2><a href="/community/{{ $notification->thread_id }}/replies/{{ $notification->reply_id }}">{{ $notification->title }}</a></h2>
                        <p><x-icon name="clock" size="15"/> <time datetime="{{ \Illuminate\Support\Carbon::parse($notification->created_at)->toIso8601String() }}">{{ \Illuminate\Support\Carbon::parse($notification->created_at)->diffForHumans() }}</time></p>
                    </div>
                    <div class="discussion-notification-actions">
                        <a class="button button-outline" href="/community/{{ $notification->thread_id }}/replies/{{ $notification->reply_id }}">View reply</a>
                        @if(! $notification->read_at)
                            <form method="post" action="/account/community-notifications/{{ $notification->id }}/read">
                                @csrf
                                <input type="hidden" name="return_to" value="{{ $view }}">
                                <button type="submit" class="button button-outline">Mark as read</button>
                            </form>
                        @endif
                    </div>
                </article>
            @empty
                <div class="discussion-notifications-empty">
                    <x-icon name="bell" size="27"/>
                    <h2>{{ $view === 'unread' ? 'All caught up' : 'No discussion notifications yet' }}</h2>
                    <p>{{ $view === 'unread' ? 'You have no unread replies on this community.' : 'Follow discussions to receive updates when new replies are approved.' }}</p>
                    <a class="button button-primary" href="{{ $view === 'unread' ? '/account/community-notifications' : '/community' }}">{{ $view === 'unread' ? 'View all activity' : 'Browse discussions' }}</a>
                </div>
            @endforelse
        </section>
        {{ $notifications->links() }}
    </div>
</x-layouts.site>
