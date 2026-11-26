@props(['place' => null, 'settings' => []])
@if($settings['local_clock_enabled'] ?? true)
    <div class="local-context" aria-label="Manitoba local information">
        <span class="local-context-place">{{ $place?->name ?? 'Manitoba' }}</span>
        <span class="local-context-date" data-manitoba-date>{{ now('America/Winnipeg')->format('D, M j') }}</span>
        <time data-manitoba-clock datetime="{{ now()->toIso8601String() }}" title="Manitoba time">{{ now('America/Winnipeg')->format('g:i a') }}</time>
        <span>Manitoba time</span>
    </div>
@endif
