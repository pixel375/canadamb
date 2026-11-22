@props(['action', 'contact', 'subject', 'canEnable' => true])
@php($receiving = $contact !== null && $contact === auth()->id())
@if($contact !== null && !$receiving && $canEnable)
    <p class="field-note">Another verified owner or manager receives new messages here.</p>
@elseif($receiving || ($contact === null && $canEnable))
    <form method="post" action="{{ $action }}">
        @csrf
        @method('PUT')
        <input type="hidden" name="enabled" value="{{ $receiving ? '0' : '1' }}">
        <button class="button button-outline" aria-label="{{ $receiving ? 'Stop new messages for ' : 'Receive messages for ' }}{{ $subject }}">
            {{ $receiving ? 'Stop new messages' : 'Receive messages' }}
        </button>
    </form>
@endif
