<x-layouts.admin title="Local time & weather">
    <div class="page-intro"><div><p class="section-label">Experience</p><h1>Local time &amp; weather</h1><p>Configure the desktop utility row and mobile menu.</p></div></div>
    <form method="post" action="/admin/local-context" class="panel form-stack">@csrf
        <input type="hidden" name="configuration_version" value="{{ \App\Support\NetworkSettings::stateVersion($state) }}">
        <h2>Manitoba clock</h2>
        <label>Clock visibility<select name="local_clock_enabled"><option value="1" @selected($draft['local_clock_enabled'] ?? true)>Show</option><option value="0" @selected(!($draft['local_clock_enabled'] ?? true))>Hide</option></select></label>
        <label>Time format<select name="clock_format"><option value="12" @selected(($draft['clock_format'] ?? '12') === '12')>12-hour (AM/PM)</option><option value="24" @selected(($draft['clock_format'] ?? '') === '24')>24-hour</option></select></label>
        <h2>Current weather</h2>
        <p class="field-note">Live: {{ ($published['weather_enabled'] ?? false) ? 'Enabled' : 'Disabled' }} · Draft credential: {{ empty($draft['weather_key_encrypted']) ? 'Not configured' : 'Configured (hidden)' }}</p>
        <p>Provider: <a class="text-link" href="https://www.weatherapi.com/" rel="noreferrer">WeatherAPI.com</a>. Uses community coordinates, shared 45-minute caching and a 2,000-call daily ceiling. No visitor location or IP is sent to the provider.</p>
        <label>Weather visibility<select name="weather_enabled"><option value="0" @selected(!($draft['weather_enabled'] ?? false))>Disabled</option><option value="1" @selected($draft['weather_enabled'] ?? false)>Enabled when configured</option></select></label>
        <label>Credential action<select name="credential_action"><option value="keep">Keep existing key</option><option value="replace">Replace key</option><option value="remove">Remove key from new configuration</option></select></label>
        <label>Weather API key<input name="weather_api_key" type="password" maxlength="128" autocomplete="new-password" spellcheck="false" placeholder="Only enter when replacing"></label>
        <p class="field-note">Stored encrypted; never displayed. Historic configuration revisions may retain encrypted old keys. Revoke old keys at the provider when rotating or removing them. Removing a key requires disabling weather.</p>
        <button class="button button-primary">Save local context draft</button>
    </form>
    <section class="panel form-stack"><h2>Publish settings</h2><p>All pending configuration changes publish together. Weather stays off until enabled with a key and published.</p><a class="button button-outline" href="/admin/settings">Review and publish network settings</a></section>
</x-layouts.admin>
