@props(['name', 'size' => 20])
@php
    $paths = [
        'pin' => 'M20 10c0 5-8 11-8 11S4 15 4 10a8 8 0 1 1 16 0Z M12 13a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z',
        'search' => 'M21 21l-4.3-4.3M19 11a8 8 0 1 1-16 0 8 8 0 0 1 16 0Z',
        'arrow' => 'M4 12h15m-6-6 6 6-6 6',
        'plus' => 'M12 5v14M5 12h14',
        'marketplace' => 'M4 10h16l-1.3 10H5.3L4 10Zm3 0 3-6m7 6-3-6M9 13v4m6-4v4',
        'map' => 'm3 6 6-3 6 3 6-3v15l-6 3-6-3-6 3V6Zm6-3v15m6-12v15',
        'person' => 'M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2m12-14a4 4 0 1 1-8 0 4 4 0 0 1 8 0Z',
        'moon' => 'M20.9 13.35A9 9 0 1 1 10.65 3.1a7 7 0 0 0 10.25 10.25Z',
        'menu' => 'M4 6h16M4 12h16M4 18h16',
        'building' => 'M3 21h18M5 21V7l8-4v18m0-11 6 3v8M8 9h2m-2 4h2m-2 4h2m6-1h1',
        'tag' => 'M20 13 11 22l-9-9V4h9l9 9ZM7.5 9h.01',
        'shield' => 'M12 22s7-3.5 7-10V5l-7-3-7 3v7c0 6.5 7 10 7 10Zm-3-10 2 2 4-4',
        'chevron' => 'm9 18 6-6-6-6',
        'photo' => 'M3 5h18v14H3zm2 12 5-5 3 3 3-3 4 5M8 9h.01',
    ];
@endphp
<svg aria-hidden="true" {{ $attributes }} width="{{ $size }}" height="{{ $size }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $paths[$name] ?? $paths['pin'] }}"/></svg>
