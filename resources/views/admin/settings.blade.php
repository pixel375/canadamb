<x-layouts.admin title="Global settings">
    <div class="page-intro"><div><h1>Global settings</h1><p>Published version {{ $state->version }}{{ $state->draft_id ? ' · Draft available' : '' }}</p></div></div>
    <div class="admin-settings-grid mb-4"><a class="panel admin-settings-card" href="/admin/theme"><x-icon name="moon" size="22"/><h3>Theme &amp; appearance</h3><p>Light and dark theme editor</p></a><a class="panel admin-settings-card" href="/admin/local-context"><x-icon name="clock" size="22"/><h3>Local time &amp; weather</h3><p>Clock and weather configuration</p></a><a class="panel admin-settings-card" href="/admin/publication-policy"><x-icon name="shield" size="22"/><h3>Moderation policy</h3><p>Publication and review rules</p></a></div>
    @if($state->draft_id)
        <section class="panel form-stack"><h2>Pending global configuration changes</h2>
            @if($changes)<ul>@foreach($changes as $change)<li>{{ $change }}</li>@endforeach</ul>@else<p>No differences detected in the listed settings groups.</p>@endif
            <p class="field-note">Publishing applies the entire pending draft across the network, including changes made on other settings pages. Credentials are never shown in this summary.</p>
            <form method="post" action="/admin/settings/publish" class="inline-actions">@csrf<input type="hidden" name="configuration_version" value="{{ \App\Support\NetworkSettings::stateVersion($state) }}"><button class="button button-primary">Publish network settings</button></form>
        </section>
    @endif
    <form method="post" action="/admin/settings" class="panel form-stack">
        @csrf
        <input type="hidden" name="configuration_version" value="{{ \App\Support\NetworkSettings::stateVersion($state) }}">
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
    <section class="panel form-stack"><h2>Commercial planning</h2><p>Internal rate cards are versioned with network settings. They cannot activate payments or advertising.</p><a class="text-link" href="/admin/commercial">Advertising and subscription planning</a></section>

</x-layouts.admin>
