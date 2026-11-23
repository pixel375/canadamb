<x-layouts.admin title="Private ownership review">
    <div class="page-intro"><div><a class="text-link" href="/admin/ownership-cases">Ownership review queue</a><h1>Ownership review · {{ $business->name }}</h1><p>{{ ucfirst($item->type) }} · {{ str_replace('_', ' ', $item->status) }}</p></div></div>
    <section class="panel form-stack">
        <h2>Private information for an independent reviewer</h2>
        <p>Applicant: {{ $actors[$item->initiated_by_id]->name ?? 'Unavailable account' }}</p>
        @if($item->type === 'transfer')
            <p>Current owner: {{ $actors[$item->outgoing_owner_id]->name ?? 'Unavailable account' }}</p>
            <p>Proposed successor: {{ $actors[$item->proposed_owner_id]->name ?? 'Unavailable account' }}</p>
            <p>{{ $item->accepted_at ? 'Successor accepted the proposed transfer.' : 'Successor has not accepted.' }}</p>
        @endif
        <h3>Submitted evidence</h3><p class="prose-text">{{ $evidence }}</p>
        <p class="field-note">This evidence must not be copied into public announcements, emails or audit notes. A current business member or case participant cannot independently approve their own transfer or dispute.</p>
    </section>
    @if($item->status === 'pending_review' || ($item->type === 'dispute' && $item->status === 'on_hold'))
        <section class="panel form-stack">
            <h2>Record independent decision</h2>
            <form method="post" action="/admin/ownership-cases/{{ $item->id }}" class="form-stack">
                @csrf
                <input type="hidden" name="version" value="{{ $version }}">
                <label>Decision<select name="decision" required>
                    @if($item->type === 'transfer')
                        <option value="rejected">Reject transfer</option>
                        <option value="approved">Approve verified transfer</option>
                    @elseif($item->status === 'pending_review')
                        <option value="dismissed">Dismiss concern and lift temporary hold</option>
                        <option value="on_hold">Confirm investigation hold (no automatic ownership changes)</option>
                    @else
                        <option value="released">Release hold following separate verification</option>
                    @endif
                </select></label>
                <label>Rejection or dismissal reason (required when rejecting/dismissing)<textarea name="reason" rows="2" maxlength="300"></textarea></label>
                <label>Independent proof method<select name="proof_method"><option value="">Choose when approving, holding or releasing</option>
                    @foreach(\App\Support\BusinessClaims::PROOF_METHODS as $key => $method)<option value="{{ $key }}">{{ $method }}</option>@endforeach
                </select></label>
                <label>Independent evidence reference<textarea name="proof_reference" minlength="12" maxlength="300" rows="3"></textarea></label>
                <label><input type="checkbox" name="authority_confirmed" value="1"> I independently verified the relevant authority and evidence, not merely an email match.</label>
                <button class="button button-primary">Save reviewed decision</button>
            </form>
        </section>
    @else
        <section class="panel"><p>Case has no action pending.</p></section>
    @endif
</x-layouts.admin>
