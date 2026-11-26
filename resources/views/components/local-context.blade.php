@props(['place' => null, 'settings' => []])
@if(($settings['local_clock_enabled'] ?? true) || (\App\Support\LocalWeather::configured($settings) && \App\Support\LocalWeather::supported($place)))
    <div class="local-context" aria-label="Manitoba local information">
        <span class="local-context-place">{{ $place?->name ?? 'Manitoba' }}</span>
        @if($settings['local_clock_enabled'] ?? true)
            <span class="local-context-date" data-manitoba-date>{{ now('America/Winnipeg')->format('D, M j') }}</span>
            <time data-manitoba-clock data-clock-format="{{ $settings['clock_format'] ?? '12' }}" datetime="{{ now()->toIso8601String() }}" title="Manitoba time">{{ now('America/Winnipeg')->format(($settings['clock_format'] ?? '12') === '24' ? 'H:i' : 'g:i a') }}</time>
            <span>Manitoba time</span>
        @endif
        @if(\App\Support\LocalWeather::configured($settings) && \App\Support\LocalWeather::supported($place))
            <span class="local-weather" data-local-weather="/local-weather/{{ $place->id }}" hidden><strong data-weather-value></strong><span data-weather-observed></span> · <a href="https://www.weatherapi.com/" rel="noreferrer">WeatherAPI.com</a></span>
            <small class="weather-disclaimer" data-weather-disclaimer hidden>Informational only; conditions may be inaccurate. Do not rely on this for safety-critical decisions. <a href="https://weather.gc.ca/" rel="noreferrer">Official weather and alerts</a>.</small>
        @endif
    </div>
@endif
