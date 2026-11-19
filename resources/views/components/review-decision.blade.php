@props(['action'])
<form class="form-stack" method="post" action="{{ $action }}">
    @csrf
    <label>Review notes<textarea name="notes" required minlength="8" maxlength="1200" rows="2"></textarea></label>
    <div class="inline-actions"><button class="button button-primary" name="decision" value="approved">Approve</button><button class="button button-outline" name="decision" value="rejected">Reject</button></div>
</form>
