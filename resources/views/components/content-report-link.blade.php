@props(['type', 'target'])
@if(auth()->check() && auth()->user()->hasVerifiedEmail() && \App\Support\ContentReports::enabled())
    <a class="text-link" href="/account/report/{{ $type }}/{{ $target }}">Report {{ $type === 'reply' ? 'reply' : 'content' }}</a>
@endif
