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
                <p>Approved replies to followed discussions, your comments and your events, visible on this community.</p>
            </div>
            <a class="button button-outline" href="/account/followed-discussions"><x-icon name="bell" size="17"/> Following</a>
        </div>
        <div class="discussion-notification-tools">
            <nav class="discussion-notification-filters" aria-label="Filter discussion notifications">
                <a href="/account/community-notifications?{{ http_build_query(array_replace($filters, ['view' => 'all'])) }}" @if($view === 'all') aria-current="page" @endif>All activity</a>
                <a href="/account/community-notifications?{{ http_build_query(array_replace($filters, ['view' => 'unread'])) }}" @if($view === 'unread') aria-current="page" @endif>Unread <span>{{ $unreadCount }}</span></a>
                <a href="/account/community-notifications?{{ http_build_query(array_replace($filters, ['view' => 'read'])) }}" @if($view === 'read') aria-current="page" @endif>Read history</a>
            </nav>
            @if($unreadCount > 0)
                <form action="/account/community-notifications/read-all" method="post">
                    @csrf
                    <input type="hidden" name="cutoff" value="{{ $readCutoff }}">
                    <input type="hidden" name="view" value="{{ $view }}">
                    @if(filled($filters['q'] ?? null))
                        <input type="hidden" name="q" value="{{ $filters['q'] }}">
                    @endif
                    @if(filled($filters['period'] ?? null))
                        <input type="hidden" name="period" value="{{ $filters['period'] }}">
                    @endif
                    <button type="submit" class="button button-outline"><x-icon name="check" size="16"/> Mark all as read</button>
                    <p class="field-note">All visible unread notifications received before this page loaded, regardless of search filters.</p>
                </form>
            @endif
        </div>
        <form method="get" action="/account/community-notifications" class="panel saved-search mb-4">
            <input type="hidden" name="view" value="{{ $view }}">
            <label>Discussion title<input type="search" name="q" maxlength="100" value="{{ $filters['q'] ?? '' }}" placeholder="Search notification topics"></label>
            <label>Period<select name="period"><option value="">All time</option>@foreach([7,30,90] as $days)<option value="{{ $days }}" @selected((string) ($filters['period'] ?? '') === (string) $days)>Last {{ $days }} days</option>@endforeach</select></label>
            <button class="button button-primary">Filter activity</button><a class="text-link" href="/account/community-notifications?view={{ $view }}">Reset</a>
        </form>
        <p class="field-note">{{ $notifications->total() }} matching notifications</p>
        @if($notifications->isNotEmpty())
            <details class="panel form-stack mb-4"><summary>Manage selected notifications</summary>
                <form id="notification-selection" method="post" action="/account/community-notifications/selected" class="form-stack mt-2">@csrf
                    <input type="hidden" name="view" value="{{ $view }}">
                    @if(filled($filters['q'] ?? null))
                        <input type="hidden" name="q" value="{{ $filters['q'] }}">
                    @endif
                    @if(filled($filters['period'] ?? null))
                        <input type="hidden" name="period" value="{{ $filters['period'] }}">
                    @endif
                    <p class="field-note">Select up to twenty notifications on this page. This affects your account only.</p>
                    <label>Action<select name="action"><option value="read">Mark selected as read</option><option value="unread">Mark selected as unread</option></select></label>
                    <div><button class="button button-outline">Apply selected action</button></div>
                </form>
            </details>
        @endif
        <section class="panel discussion-notification-list" aria-label="Discussion notification list">
            @forelse($notifications as $notification)
                <article class="discussion-notification-row{{ $notification->read_at ? '' : ' is-unread' }}">
                    <span class="discussion-notification-indicator" aria-hidden="true"></span>
                    <div class="discussion-notification-body">
                        <label class="checkbox-line"><input form="notification-selection" type="checkbox" name="notifications[]" value="{{ $notification->id }}"> Select notification</label>
                        <p class="section-label">{{ $notification->read_at ? 'Read reply' : 'New reply' }}</p>
                        <form method="post" action="/account/community-notifications/{{ $notification->id }}/open">@csrf
                            <h2><button class="notification-open-link" type="submit">{{ $notification->title }}</button></h2>
                        </form>
                        <p><strong>{{ $notification->actor_name }}</strong> {{ in_array($notification->reply_id, $directReplies, true) ? 'replied to your comment.' : (in_array((int) $notification->thread_id, $eventThreads, true) ? 'commented on this event.' : 'posted a reply in this discussion.') }}</p>
                        <p><x-icon name="clock" size="15"/> <time datetime="{{ \Illuminate\Support\Carbon::parse($notification->created_at)->toIso8601String() }}">{{ \Illuminate\Support\Carbon::parse($notification->created_at)->diffForHumans() }}</time></p>
                    </div>
                    <div class="discussion-notification-actions">
                        <form method="post" action="/account/community-notifications/{{ $notification->id }}/open">@csrf
                            <button class="button button-outline" type="submit">View reply</button>
                        </form>
                        @if(! $notification->read_at)
                            <form method="post" action="/account/community-notifications/{{ $notification->id }}/read">
                                @csrf
                                <input type="hidden" name="return_to" value="{{ $view }}">
                    @if(filled($filters['q'] ?? null))
                        <input type="hidden" name="q" value="{{ $filters['q'] }}">
                    @endif
                    @if(filled($filters['period'] ?? null))
                        <input type="hidden" name="period" value="{{ $filters['period'] }}">
                    @endif
                                <button type="submit" class="button button-outline">Mark as read</button>
                            </form>
                        @else
                            <form method="post" action="/account/community-notifications/selected">@csrf
                                <input type="hidden" name="view" value="{{ $view }}"><input type="hidden" name="notifications[]" value="{{ $notification->id }}"><input type="hidden" name="action" value="unread">
                    @if(filled($filters['q'] ?? null))
                        <input type="hidden" name="q" value="{{ $filters['q'] }}">
                    @endif
                    @if(filled($filters['period'] ?? null))
                        <input type="hidden" name="period" value="{{ $filters['period'] }}">
                    @endif
                                <button class="button button-outline">Mark unread</button>
                            </form>
                        @endif
                    </div>
                </article>
            @empty
                <div class="discussion-notifications-empty">
                    <x-icon name="bell" size="27"/>
                    @if(filled($filters['q'] ?? null) || filled($filters['period'] ?? null))
                        <h2>No notifications match these filters</h2><a class="button button-outline" href="/account/community-notifications?view={{ $view }}">Clear filters</a>
                    @else
                    <h2>{{ match($view) { 'unread' => 'All caught up', 'read' => 'No read notifications yet', default => 'No discussion notifications yet' } }}</h2>
                    <p>{{ $view === 'unread' ? 'You have no unread replies on this community.' : 'Follow discussions to receive updates when new replies are approved.' }}</p>
                    <a class="button button-primary" href="{{ $view === 'unread' ? '/account/community-notifications' : '/community' }}">{{ $view === 'unread' ? 'View all activity' : 'Browse discussions' }}</a>
                    @endif
                </div>
            @endforelse
        </section>
        {{ $notifications->links() }}
    </div>
</x-layouts.site>
