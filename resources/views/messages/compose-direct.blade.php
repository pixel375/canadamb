<x-layouts.site title="New private conversation">
    <div class="container page-shell narrow">
        <div class="page-intro"><div><a class="text-link" href="/users/{{ $recipient->id }}">Back to member profile</a><h1>{{ $kind === 'official' ? 'New official conversation' : 'New direct conversation' }}</h1><p>To {{ $recipient->name }}</p></div></div>
        <form class="panel form-stack" method="post" action="/account/messages/member/{{ $recipient->id }}/{{ $kind }}">@csrf
            <p>Only start a conversation for a relevant, respectful purpose. Do not send unsolicited advertising, passwords or payment details.</p>
            @if($kind === 'official')<p class="notice">This conversation will be identified as official CanadaMB communication. Initiation is audited.</p>@endif
            <label>Message<textarea name="body" required maxlength="2000" rows="5" data-character-count>{{ old('body') }}</textarea><small class="field-note" data-character-feedback></small></label>
            <a class="text-link" href="/safety">Messaging safety</a>
            <button class="button button-primary">Start conversation</button>
        </form>
    </div>
</x-layouts.site>
