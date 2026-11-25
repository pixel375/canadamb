<x-layouts.site title="Download my data">
    <div class="container page-shell listing-editor">
        <div class="page-intro"><div><a class="text-link" href="/account/settings">Account settings</a><h1>Download my data</h1><p>Export selected records associated with your account across CanadaMB communities.</p></div></div>
        <section class="panel form-stack"><h2>Choose a section</h2><p>Downloads are private JSON files. Confirm your password for every download. Keep exported files secure, especially messages and property details.</p>
            <form method="post" action="/account/data-export" class="form-stack">@csrf
                <label>Data section<select name="section"><option value="profile">Account profile</option>@foreach($counts as $key => $count)<option value="{{ $key }}" @selected(old('section') === $key)>{{ ucfirst(str_replace('-', ' ', $key)) }} ({{ $count }})</option>@endforeach</select></label>
                <label>Continue after record ID (optional)<input type="number" name="after_id" min="0" value="{{ old('after_id') }}"></label>
                <p class="field-note">Each download contains up to 500 records. If next_after_id is present and not null, enter that value to download the next batch. This is a current-data export, not a historical snapshot.</p>
                <x-password-field name="current_password" label="Current password" autocomplete="current-password"/>
                <button class="button button-primary">Download JSON</button>
            </form>
        </section>
        <section class="panel form-stack"><h2>What is included?</h2><p>Your account profile, authored content, sent messages, private property details, saved listings and jobs, followed discussions, notification history, business memberships, claims, access-request status, blocked-member IDs and your own reports. Additional sections appear when their database tables are available. Conversation metadata includes only your participation, your own read/archive markers and associated record IDs. Listing, property and sent-photo metadata excludes file paths and image contents. Subsequent batches have distinct filenames to help keep your exports organized. Received messages, image files, other people’s records, internal review notes, credentials and security tokens are excluded. For a more complete access request or account deletion, contact the team.</p><a class="text-link" href="/contact?topic=privacy">Privacy and account requests</a></section>
    </div>
</x-layouts.site>
