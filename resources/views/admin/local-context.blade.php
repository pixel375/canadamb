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
    <section class="panel form-stack">
        <h2>Weather diagnostics</h2>
        <p>Shared provider request attempts today (UTC): <strong>{{ $usage === null ? 'Unavailable' : number_format($usage).' / '.number_format(\App\Support\LocalWeather::DAILY_LIMIT) }}</strong>. This is a local counter, not the provider's billing record.</p>
        <p class="field-note">Test the saved draft credential without enabling or publishing weather. Tests use the same daily request budget and a separate diagnostic cache. Only a valid observation is reported; provider errors and credentials are not displayed.</p>
        @if($places->isEmpty())
            <p>No active community portal has supported weather coordinates.</p>
        @elseif(empty($draft['weather_key_encrypted']))
            <p>Save an API credential first to make testing available.</p>
        @else
            <form method="post" action="/admin/local-context/diagnostic" class="form-stack">@csrf
                <input type="hidden" name="configuration_version" value="{{ \App\Support\NetworkSettings::stateVersion($state) }}">
                <label>Community<select name="location_id" required>@foreach($places as $place)<option value="{{ $place->id }}">{{ $place->name }}</option>@endforeach</select></label>
                <label class="checkbox"><input type="checkbox" name="confirm_test" value="1" required> Allow a diagnostic provider request using the saved credential</label>
                <div><button class="button button-outline">Check saved weather credential</button></div>
            </form>
        @endif
    </section>
    <section class="panel form-stack"><h2>Publish settings</h2><p>All pending configuration changes publish together. Weather stays off until enabled with a key and published.</p><a class="button button-outline" href="/admin/settings">Review and publish network settings</a></section>
</x-layouts.admin>
