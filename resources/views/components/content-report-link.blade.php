@props(['type', 'target'])
@if(auth()->check() && auth()->user()->hasVerifiedEmail() && \App\Support\ContentReports::enabled())
    <a class="text-link" href="/account/report/{{ $type }}/{{ $target }}">Report {{ match($type) { 'reply' => 'reply', 'profile' => 'member', 'message' => 'message', default => 'content' } }}</a>
@endif
