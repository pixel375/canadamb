@props(['action', 'version'])
<form class="form-stack" method="post" action="{{ $action }}">
    @csrf
    <input type="hidden" name="version" value="{{ $version }}">
    <label>Reason shown to the submitter when rejected<textarea name="reason" maxlength="300" rows="2"></textarea></label>
    <label>Internal reviewer notes<textarea name="notes" required minlength="8" maxlength="1200" rows="2"></textarea></label>
    <div class="inline-actions"><button class="button button-primary" name="decision" value="approved">Approve</button><button class="button button-outline" name="decision" value="rejected">Reject</button></div>
</form>
