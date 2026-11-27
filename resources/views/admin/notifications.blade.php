<x-layouts.admin title="Notifications">
    <div class="page-intro"><div><p class="section-label">Experience &amp; configuration</p><h1>Notification centre</h1><p>Review in-site notification activity across the shared network.</p></div></div>
    <form method="get" action="/admin/notifications" class="panel saved-search mb-4">
        <label>Reporting period<select name="days">@foreach([7,30,90] as $period)<option value="{{ $period }}" @selected($days === $period)>Last {{ $period }} days</option>@endforeach</select></label>
        <button class="button button-primary">Update report</button>
    </form>
    <section class="panel form-stack">
        <h2>Discussion notification activity</h2>
        <div class="form-grid">
            <div><p class="section-label">Created, still publicly eligible</p><strong>{{ number_format($metrics['created']) }}</strong></div>
            <div><p class="section-label">Unread</p><strong>{{ number_format($metrics['unread']) }}</strong></div>
            <div><p class="section-label">Distinct recipients</p><strong>{{ number_format($metrics['recipients']) }}</strong></div>
        </div>
        <p class="field-note">These are in-site records, not email or push delivery receipts. Hidden discussions and comments are excluded; mirrored records are counted once.</p>
    </section>
    @if($broadcastEnabled)
        <section class="panel form-stack">
            <h2>Preview a network announcement</h2>
            <p>Only verified members who separately opted into announcements are counted. This is an audited preview, not a send operation.</p>
            <form method="post" action="/admin/notifications/preview" class="form-stack">@csrf
                <label>Title<input name="title" required maxlength="120" value="{{ old('title') }}"></label>
                <label>Message<textarea name="body" required maxlength="1000" rows="5">{{ old('body') }}</textarea></label>
                <label>Audience<select name="audience"><option value="all">All opted-in members</option><option value="business-owners" @selected(old('audience') === 'business-owners')>Opted-in business owners</option><option value="selected" @selected(old('audience') === 'selected')>Selected opted-in members</option></select></label>
                <label>Member reference numbers (selected only)<textarea name="member_ids" maxlength="600" rows="2">{{ old('member_ids') }}</textarea></label>
                <button class="button button-primary">Preview announcement</button>
            </form>
        </section>
    @endif
    <section class="panel form-stack">
        <h2>Availability and preferences</h2>
        <p>Announcement previews: {{ $broadcastEnabled ? 'Available' : 'Staged, not active' }}</p>
        <p>In-site delivery: {{ $announcementDelivery ? 'Available with explicit confirmation' : 'Disabled' }}</p>
        @if($announcementMetrics)
            <p>{{ number_format($announcementMetrics->retained) }} retained member receipts in this period · {{ number_format($announcementMetrics->read_total) }} marked read.</p>
            <p class="field-note">These are in-site read markers, not proof a person read the message. Account deletion may remove receipts without changing historical delivery counts.</p>
        @endif
        @if($announcementHistory->isNotEmpty())
            <h3>Recent in-site announcements (up to 20)</h3>
            @foreach($announcementHistory as $sent)<div class="item-row"><div><strong>{{ $sent->title }}</strong><p>{{ $sent->recipient_count }} notification centres · {{ $sent->created_at }}</p></div></div>@endforeach
        @endif
        <p>Member preferences: {{ $preferencesEnabled ? 'Available' : 'Staged, not active' }}</p>
        @if($paused !== null)<p>{{ number_format($paused) }} accounts have paused discussion notifications.</p>@endif
        <p>Owner submission updates: {{ $ownerEnabled ? 'Available' : 'Staged, not active' }}</p>
        @if($ownerEnabled)
            <h3>Recorded status transitions in this period</h3>
            @foreach(['approved', 'rejected', 'removed', 'expired'] as $decision)<p>{{ ucfirst($decision) }}: {{ number_format($ownerMetrics->get($decision, 0)) }}</p>@endforeach
            <p class="field-note">Historical aggregate decisions only; no member details or delivery receipts.</p>
        @endif
        <p>Event discussion linkage: {{ $eventsEnabled ? 'Available' : 'Staged, not active' }}</p>
        <h3>Web Push preparation</h3>
        <p>Device registry: {{ $pushEnabled ? 'Available' : 'Staged, not active' }}</p>
        <p>Public application key: {{ $pushKeyConfigured ? 'Configured (format checked)' : 'Not configured' }}</p>
        @if($pushCount !== null)<p>{{ number_format($pushCount) }} registered devices across the network.</p>@endif
        <p>Generic push delivery: {{ $pushDeliveryEnabled && $pushSigningReady ? 'Enabled' : 'Disabled or not configured' }}. Email delivery is not available. No sends or permission prompts are initiated from this page.</p>
        <p>Private signing key and contact: {{ $pushSigningReady ? 'Configured and matched' : 'Not ready' }}</p>
        @if($pushDeliveryEnabled)<h3>Current device delivery states</h3>@foreach(['idle', 'accepted', 'retry', 'failed', 'paused', 'baseline'] as $state)<p>{{ ucfirst($state) }}: {{ number_format($pushStates->get($state, 0)) }}</p>@endforeach<p class="field-note">Provider acceptance is not proof of delivery or reading.</p>@endif
        <p class="field-note">After review, configure CANADAMB_PUSH_PUBLIC_KEY in the private hosting environment and enable CANADAMB_PUSH_DEVICES only after migration approval. Configure matching CANADAMB_PUSH_PRIVATE_KEY (base64 of a P-256 PEM private key) and CANADAMB_PUSH_SUBJECT (mailto: operator contact) only in the private hosting environment. Enable CANADAMB_PUSH_DELIVERY separately after reviewed migration and device acceptance. Never place the private VAPID key in browser code, GitHub or administrator forms. Device registration does not imply marketing consent.</p>
        <p class="field-note">Discussion preferences do not constitute consent to receive administrator broadcasts or marketing messages.</p>
    </section>
</x-layouts.admin>
