@props(['path', 'label', 'place' => null])
@php
    $text = match (true) {
        !auth()->check() => 'Sign in to message',
        !auth()->user()->hasVerifiedEmail() => 'Verify email to message',
        default => $label,
    };
@endphp
<a class="button button-outline" href="{{ $path }}" @if($place) aria-label="{{ $text }} in {{ $place }}" @endif>{{ $text }}</a>
