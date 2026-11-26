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
    <section class="panel form-stack">
        <h2>Availability and preferences</h2>
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
        <p>Delivery and administrator broadcasts: not available. No sends or permission prompts are initiated from this page.</p>
        <p class="field-note">After review, configure CANADAMB_PUSH_PUBLIC_KEY in the private hosting environment and enable CANADAMB_PUSH_DEVICES only after migration approval. Keep the future sender's private VAPID key out of browser code, GitHub and administrator forms. Device registration does not imply marketing consent.</p>
        <p class="field-note">Discussion preferences do not constitute consent to receive administrator broadcasts or marketing messages.</p>
    </section>
</x-layouts.admin>
