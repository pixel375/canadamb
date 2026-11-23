<x-layouts.admin title="Private location review">
    <a class="text-link" href="/admin/business-branches">All location requests</a>
    <div class="page-intro"><div><h1>{{ $business->name }} · {{ ucfirst(str_replace('_', ' ', $item->action)) }}</h1><p>Current status: {{ $item->status }}</p></div></div>
    <section class="panel form-stack">
        <p>Community: {{ $location?->name ?? 'Unavailable' }}</p>
        @if($branch)<p>Existing address: {{ $branch->address }} · Current operational status: {{ $branch->operational_status }}</p>@endif
        @if($item->proposed_address)<p>Proposed physical address: {{ $item->proposed_address }}</p>@endif
        <h2>Private evidence</h2><p class="prose-text">{{ $evidence }}</p>
        <p class="field-note">Review independently. Do not put identity documents, passwords, or contact evidence into public records. Source IDs, ownership and historical records must not change.</p>
    </section>
    @if($item->status === 'pending')
        <section class="panel form-stack">
            <h2>Independent decision</h2>
            <form method="post" action="/admin/business-branches/{{ $item->id }}" class="form-stack">
                @csrf<input type="hidden" name="version" value="{{ $version }}">
                <label>Decision<select name="decision"><option value="rejected">Reject</option><option value="approved">Approve reviewed change</option></select></label>
                <label>Rejection reason<textarea name="reason" minlength="12" maxlength="300" rows="2"></textarea></label>
                <label>Independent proof method<select name="proof_method"><option value="">Choose for approval</option>
                    @foreach(\App\Support\BusinessClaims::PROOF_METHODS as $key => $description)
                        <option value="{{ $key }}">{{ $description }}</option>
                    @endforeach
                </select></label>
                <label>Private proof reference<textarea name="proof_reference" minlength="12" maxlength="300" rows="2"></textarea></label>
                <label><input type="checkbox" name="authority_confirmed" value="1"> I independently verified this address/status change and the submitting owner’s authority.</label>
                <button class="button button-primary">Save decision</button>
            </form>
        </section>
    @endif
</x-layouts.admin>
