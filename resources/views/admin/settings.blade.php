<x-layouts.admin title="Global settings">
    <div class="page-intro"><div><h1>Global settings</h1><p>Published version {{ $state->version }} · Revision {{ $state->published_id }}{{ $state->draft_id ? ' · Draft available' : '' }}</p></div></div>
    <form method="post" action="/admin/settings" class="panel form-stack">
        @csrf
        <h2>Module availability</h2>
        @foreach($modules as $key => $label)
            <fieldset class="form-stack pb-3">
                <legend class="font-bold">{{ $label }}</legend>
                <label class="checkbox"><input type="checkbox" name="modules[{{ $key }}]" value="1" @checked($settings['modules'][$key] ?? false)> Available network-wide</label>
                <label class="checkbox"><input type="checkbox" name="overrides[{{ $key }}]" value="1" @checked(in_array($key, $settings['permitted_module_overrides'], true))> Allow local portals to hide this module</label>
            </fieldset>
        @endforeach
        <fieldset class="form-stack pb-3">
            <legend class="font-bold">Optional web app installation</legend>
            <label class="checkbox"><input type="checkbox" name="pwa_install_enabled" value="1" @checked($settings['pwa_install_enabled'] ?? true)> Allow mobile visitors to install this website as an app</label>
            <p class="field-note">Shows an install link in the mobile footer only when the browser supports it. Each city has its own branded app; no private pages are stored for offline use.</p>
        </fieldset>
        <fieldset class="form-stack pb-3">
            <legend class="font-bold">Prelaunch registration</legend>
            <input type="hidden" name="prelaunch_test_registration_enabled" value="0">
            <label class="checkbox"><input type="checkbox" name="prelaunch_test_registration_enabled" value="1" @checked($settings['prelaunch_test_registration_enabled'] ?? false)> Allow prelaunch test registration</label>
            <p class="field-note">Once published, this permits registration on CanadaMB from approved tester IP addresses during prelaunch.</p>
            @if(config('network.prelaunch'))<a href="/admin/test-accounts">Review pending test accounts</a>@endif
        </fieldset>
        <div><button class="button button-primary">Save draft</button></div>
    </form>
    <form method="post" action="/admin/settings" class="panel form-stack" id="approval-policy">
        @csrf
        <h2>Approval policy</h2>
        <p>Automatic approval makes eligible submissions public before a moderator reviews them. Only new submissions qualify. Marketplace photo listings, listing edits and relists, job edits, new business profiles, and ownership claims require manual review.</p>
        <p>Local portals can require review even when the network allows automatic approval.</p>
        <div class="form-grid">
            @foreach($policyTypes as $type => $label)
                <label>{{ $label }}
                    <select name="approval_policies[{{ $type }}]">
                        <option value="manual_review" @selected(old('approval_policies.'.$type, $draftPolicies[$type]) === 'manual_review')>Manual review</option>
                        <option value="auto_approve" @selected(old('approval_policies.'.$type, $draftPolicies[$type]) === 'auto_approve')>Automatic approval</option>
                    </select>
                    <small class="field-note">Published · {{ $publishedPolicies[$type] === 'auto_approve' ? 'Automatic approval' : 'Manual review' }}</small>
                </label>
            @endforeach
        </div>
        <div><button class="button button-primary">Save approval policy draft</button></div>
    </form>
    @if($state->draft_id)
        <form method="post" action="/admin/settings/publish" class="panel form-stack">
            @csrf
            <input type="hidden" name="revision" value="{{ $state->draft_id }}">
            <h2>Draft auto-approval preview</h2>
            <p>Eligible submissions to approve automatically under revision {{ $state->draft_id }}</p>
            <p>{{ implode(', ', $draftAutomaticTypes) ?: 'None' }}</p>
            <p>Publishing applies the full revision, including pending appearance, module, and approval settings.</p>
            <div><button class="button button-primary">Publish network settings</button></div>
        </form>
    @endif
    @if($state->previous_id)
        <form method="post" action="/admin/settings/rollback" class="panel form-stack">
            @csrf
            <input type="hidden" name="revision" value="{{ $state->previous_id }}">
            <h2>Rollback preview</h2>
            <p>Eligible submissions to approve automatically under previous revision {{ $state->previous_id }}</p>
            <p>{{ implode(', ', $previousAutomaticTypes) ?: 'None' }}</p>
            <p>Rolling back restores the full previous revision. It applies to future submissions.</p>
            <div><button class="button button-outline">Restore previous revision</button></div>
        </form>
    @endif
</x-layouts.admin>
