@props(['path', 'approved' => false, 'threadControls' => false, 'pinned' => false, 'locked' => false, 'version' => null])
<form method="post" action="{{ $path }}" class="form-stack">@csrf
    @if($version)<input type="hidden" name="version" value="{{ $version }}">@endif
    <label>Reason for rejection<input name="reason" maxlength="300"></label>
    <div class="inline-actions">
        @if($approved && $threadControls)
            <button class="button button-outline" name="action" value="{{ $pinned ? 'unpin' : 'pin' }}">{{ $pinned ? 'Unpin' : 'Pin' }}</button>
            <button class="button button-outline" name="action" value="{{ $locked ? 'unlock' : 'lock' }}">{{ $locked ? 'Unlock' : 'Lock' }}</button>
        @elseif(!$approved)
            <button class="button button-primary" name="action" value="approve">Approve</button>
        @endif
        <button class="button button-outline" name="action" value="reject">{{ $approved ? 'Remove from public view' : 'Reject' }}</button>
    </div>
</form>
