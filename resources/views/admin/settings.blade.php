<x-layouts.admin title="Global settings">
    <div class="page-intro"><div><h1>Global settings</h1><p>Published version {{ $state->version }}{{ $state->draft_id ? ' · Draft available' : '' }}</p></div></div>
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
    @if($state->draft_id)
        <form method="post" action="/admin/settings/publish" class="panel inline-actions">@csrf
            <p>This publishes pending appearance and module settings together.</p>
            <button class="button button-primary">Publish network settings</button>
        </form>
    @endif
</x-layouts.admin>
