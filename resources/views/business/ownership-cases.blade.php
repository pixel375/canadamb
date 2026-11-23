<x-layouts.site title="Ownership requests">
    <div class="container page-shell narrow">
        <div class="page-intro"><div><h1>Business ownership requests</h1><p>Transfers require the new owner's acceptance and an independent review. Disputes temporarily pause ownership and staff changes, not everyday customer messages.</p></div><a class="text-link" href="/account/businesses">My businesses</a></div>
        <section class="panel">
            @forelse($items as $item)
                <article class="form-stack border-b border-gray-300 py-4">
                    <h2>{{ $item->business_name }}</h2>
                    <p>{{ $item->type === 'transfer' ? 'Ownership transfer' : 'Ownership concern' }} · {{ str_replace('_', ' ', ucfirst($item->status)) }}</p>
                    <p class="field-note">Requested {{ $item->created_at }}. Only an independent network reviewer can approve a transfer or resolve a dispute.</p>
                    @if($item->type === 'transfer' && $item->proposed_owner_id === $member && $item->status === 'awaiting_acceptance')
                        <p>Someone who currently owns this business has proposed transferring their ownership to your account. Accepting does not grant ownership yet, and does not expose their private conversation history.</p>
                        @foreach(['accept' => 'Accept for review', 'decline' => 'Decline'] as $choice => $label)
                            <form method="post" action="/account/ownership-cases/{{ $item->id }}/respond">
                                @csrf
                                <input type="hidden" name="action" value="{{ $choice }}">
                                <input type="hidden" name="version" value="{{ \App\Support\ModerationDecision::version('ownership-case', $item) }}">
                                <button class="button {{ $choice === 'accept' ? 'button-primary' : 'button-outline' }}">{{ $label }}</button>
                            </form>
                        @endforeach
                    @endif
                    @if($item->initiated_by_id === $member && in_array($item->status, ['awaiting_acceptance', 'pending_review'], true))
                        <form method="post" action="/account/ownership-cases/{{ $item->id }}/respond">
                            @csrf
                            <input type="hidden" name="action" value="withdraw">
                            <input type="hidden" name="version" value="{{ \App\Support\ModerationDecision::version('ownership-case', $item) }}">
                            <button class="button button-outline">Withdraw request</button>
                        </form>
                    @endif
                </article>
            @empty <p>No ownership requests for this account.</p> @endforelse
            {{ $items->links() }}
        </section>
    </div>
</x-layouts.site>
