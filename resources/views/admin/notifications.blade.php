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
        <p>Event discussion linkage: {{ $eventsEnabled ? 'Available' : 'Staged, not active' }}</p>
        <p>Web Push and administrator broadcasts: not available. No permission prompts, external delivery or broadcast sends are initiated from this page.</p>
        <p class="field-note">Discussion preferences do not constitute consent to receive administrator broadcasts or marketing messages.</p>
    </section>
</x-layouts.admin>
