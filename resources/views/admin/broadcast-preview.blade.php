<x-layouts.admin title="Announcement preview">
    <div class="page-intro"><div><h1>Announcement preview</h1><p>Nothing was sent. This preview does not create a broadcast or a delivery job.</p></div></div>
    <section class="panel form-stack">
        <p class="section-label">Draft</p>
        <h2>{{ $draft['title'] }}</h2>
        <p class="preserve-lines">{{ $draft['body'] }}</p>
        <p>Audience: {{ match($draft['audience']) { 'all' => 'All opted-in members', 'business-owners' => 'Opted-in business owners', 'selected' => 'Selected opted-in members' } }}</p>
        <p><strong>{{ number_format($count) }}</strong> eligible verified members at preview time.</p>
        <p class="field-note">Recipients are not identified here. Consent and eligibility must be checked again before any future delivery.</p>
        @if($confirmation)
            <form method="post" action="/admin/notifications/send" class="form-stack">@csrf
                <input type="hidden" name="confirmation" value="{{ $confirmation }}">
                <label class="checkbox-line"><input type="checkbox" name="confirm_send" value="1" required> Confirm in-site delivery to this exact audience.</label>
                <p class="field-note">No email or push is sent. Confirmation expires in 10 minutes. Audience changes require a new preview.</p>
                <button class="button button-primary">Deliver announcement</button>
            </form>
        @else
            <p>Delivery is unavailable during prelaunch, without activation, or for empty or oversized audiences.</p>
        @endif
        <a class="button button-outline" href="/admin/notifications">Return to notification centre</a>
    </section>
</x-layouts.admin>
