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
                    @if($counts['messages'])<a class="text-link" href="/account/messages?view=unread">Open unread messages <x-icon name="arrow" size="16"/></a>@endif
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
                            @if($counts['notifications'])<a class="text-link" href="/account/community-notifications?view=unread">Open unread replies <x-icon name="arrow" size="16"/></a>@endif
                            <a class="text-link" href="/account/followed-discussions">Following discussions <x-icon name="arrow" size="16"/></a>
                        </div>
                    @endif
                </div>
            </section>
        </div>
        @if(\App\Support\JobSearches::enabled(request()->attributes->get('site')))
            <section class="panel form-stack"><h2>Job alerts</h2>
                <p>{{ $jobAlertsEnabled ? ($matchingSearches ? $matchingSearches.' saved searches have new matches.' : 'No saved searches have new matches on this portal.') : 'Job alert summaries are hidden by your preferences.' }}</p>
                <a class="button button-outline" href="/account/job-searches">Open job alerts</a>
            </section>
        @endif
        @if($preferencesEnabled)
            <section class="panel form-stack"><h2>Notification preferences</h2><p>These settings apply across CanadaMB communities. Existing notification history and private messages are preserved.</p>
                <form class="form-stack" method="post" action="/account/notifications/preferences">@csrf
                    <input type="hidden" name="version" value="{{ \App\Support\NotificationPreferences::version($preferences) }}">
                    <label>New replies to followed discussions<select name="discussion_replies"><option value="1" @selected($preferences['discussion_replies'])>Notify me</option><option value="0" @selected(!$preferences['discussion_replies'])>Pause new notifications</option></select></label>
                    <label>Job alerts in this dashboard<select name="job_summary"><option value="1" @selected($preferences['job_summary'])>Show summary</option><option value="0" @selected(!$preferences['job_summary'])>Hide summary</option></select></label>
                    <p class="field-note">Pausing discussion notifications does not unfollow discussions. Missed alerts are not backfilled. Job summary settings do not change individual saved searches.</p>
                    <button class="button button-primary">Save notification preferences</button>
                </form>
            </section>
        @endif
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
                                <form method="post" action="/account/community-notifications/{{ $notification->id }}/open">@csrf
                                    <h3><button class="notification-open-link" type="submit">{{ $notification->title }}</button></h3>
                                </form>
                                <p><strong>{{ $notification->actor_name }}</strong> replied to a discussion you follow.</p>
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
