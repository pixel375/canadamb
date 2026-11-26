<x-layouts.site title="Notifications">
    <div class="container page-shell">
        <div class="page-intro">
            <div><h1>Notifications</h1><p>Keep up with your messages, discussion replies and event comments.</p></div>
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
                            {{ $replyUnread ? $replyUnread.' unread '.\Illuminate\Support\Str::plural('reply notification', $replyUnread) : 'No unread discussion replies.' }}
                        @else
                            Discussion notifications are not available on this portal.
                        @endif
                    </p>
                    @if($discussionsEnabled)
                        <div class="activity-card-links">
                            <a class="text-link" href="/account/community-notifications">All discussion notifications <x-icon name="arrow" size="16"/></a>
                            @if($replyUnread)<a class="text-link" href="/account/community-notifications?view=unread">Open unread replies <x-icon name="arrow" size="16"/></a>@endif
                            <a class="text-link" href="/account/followed-discussions">Following discussions <x-icon name="arrow" size="16"/></a>
                        </div>
                    @endif
                </div>
            </section>
        </div>
        @if($announcements)
            <section class="homepage-section activity-panel">
                <div class="section-heading"><h2>Network announcements</h2><span>{{ $announcementUnread }} unread</span></div>
                <div class="panel">
                    @forelse($announcements as $announcement)
                        <article class="item-row"><div><p class="section-label">Official CanadaMB announcement</p><h3>{{ $announcement->title }}</h3><p class="preserve-lines">{{ $announcement->body }}</p><p>{{ \Illuminate\Support\Carbon::parse($announcement->created_at)->diffForHumans() }}</p></div>
                            @if(!$announcement->read_at)<form method="post" action="/account/notifications/announcements/{{ $announcement->id }}/read">@csrf<button class="button button-outline">Mark as read</button></form>@endif
                        </article>
                    @empty<p>No network announcements yet.</p>@endforelse
                </div>
                {{ $announcements->links() }}
            </section>
        @endif
        @if($broadcastEnabled)
            <section class="panel form-stack">
                <h2>Optional network announcements</h2>
                <p>Choose whether to receive future non-marketing announcements in your CanadaMB notification centre. This does not enable email, marketing messages or Web Push delivery. Opting out stops new announcements but keeps your existing history.</p>
                <form method="post" action="/account/notifications/announcement-consent" class="form-stack">@csrf
                    <label>Announcements<select name="opted_in"><option value="0" @selected(!$broadcastOptedIn)>Do not receive announcements</option><option value="1" @selected($broadcastOptedIn)>Receive announcements</option></select></label>
                    <button class="button button-primary">Save announcement preference</button>
                </form>
            </section>
        @endif
        @if($pushEnabled)
            <section class="panel form-stack" data-push-devices data-public-key="{{ $pushKey ?? '' }}">
                <h2>Device notifications</h2>
                <p>Register this browser for future notification delivery. Delivery is not available yet. Registration is optional and specific to your account and website.</p>
                <form hidden data-push-token>@csrf</form>
                <div class="form-grid" hidden data-push-controls>
                    @if($pushKey)<button type="button" class="button button-primary" data-push-register>Register this device</button>@endif
                    <button type="button" class="button button-outline" data-push-unsubscribe>Unsubscribe this browser</button>
                </div>
                <p role="status" data-push-status>{{ $pushKey ? 'Checking browser support…' : 'Registration unavailable on this portal.' }}</p>
                <h3>Registered devices across the network</h3>
                @forelse($pushDevices as $device)
                    <div class="item-row"><div><strong>{{ $device->site_name }}</strong><p>Device #{{ $device->id }} · Registered {{ \Illuminate\Support\Carbon::parse($device->created_at)->diffForHumans() }}</p></div>
                        <form method="post" action="/account/notifications/devices/{{ $device->id }}">@csrf @method('DELETE')<button class="button button-outline">Remove registration</button></form>
                    </div>
                @empty<p>No registered devices.</p>@endforelse
                <p class="field-note">Signing out of the network or changing your password removes your registered devices. Register again after signing in if desired. Browser notification permission can also be revoked in browser settings.</p>
            </section>
        @endif
        @if($ownerEnabled)
            <section class="homepage-section activity-panel">
                <div class="section-heading"><h2>My submission updates</h2><span>{{ $ownerUnread }} unread</span></div>
                <div class="panel">
                    @forelse($ownerAlerts as $alert)
                        <article class="item-row">
                            <div>
                                <h3>{{ \App\Support\OwnerNotifications::description($alert->kind, $alert->decision) }}</h3>
                                <p>Reference #{{ $alert->target_id }} · {{ \Illuminate\Support\Carbon::parse($alert->created_at)->diffForHumans() }}{{ $alert->read_at ? '' : ' · Unread' }}</p>
                                <a class="text-link" href="{{ \App\Support\OwnerNotifications::destination($alert->kind) }}">Manage my submissions</a>
                                @if(in_array($alert->decision, ['rejected', 'removed'], true))<a class="text-link" href="/contact">Contact support about this decision</a>@endif
                            </div>
                            @if(!$alert->read_at)<form method="post" action="/account/notifications/updates/{{ $alert->id }}/read">@csrf<button class="button button-outline">Mark as read</button></form>@endif
                        </article>
                    @empty
                        <p>No submission updates on this portal yet.</p>
                    @endforelse
                </div>
                {{ $ownerAlerts->links() }}
            </section>
        @endif
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
                    <label>Discussion replies and event comments<select name="discussion_replies"><option value="1" @selected($preferences['discussion_replies'])>Notify me</option><option value="0" @selected(!$preferences['discussion_replies'])>Pause new notifications</option></select></label>
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
                                <p><strong>{{ $notification->actor_name }}</strong> {{ in_array($notification->reply_id, $directReplies, true) ? 'replied to your comment.' : (in_array((int) $notification->thread_id, $eventThreads, true) ? 'commented on this event.' : 'posted a reply in this discussion.') }}</p>
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
