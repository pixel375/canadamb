<x-layouts.admin title="Test-payment ledger">
    <div class="page-intro"><div><h1>Test-payment ledger</h1><p>Read-only sandbox reconciliation. These are not customer payments, invoices or accounting records.</p></div><a class="button button-outline" href="/admin/commercial">Commercial planning</a></div>
    <form method="get" class="panel form-stack">
        <div class="form-grid"><label>Attempt status<select name="status"><option value="">All</option>@foreach(['created','paid','expired','failed'] as $state)<option value="{{ $state }}" @selected(($filters['status'] ?? '') === $state)>{{ ucfirst($state) }}</option>@endforeach</select></label><label>Campaign reference<input type="number" name="campaign" min="1" value="{{ $filters['campaign'] ?? '' }}"></label></div>
        <button class="button button-primary">Filter test attempts</button>
    </form>
    <section class="panel form-stack mt-4">
        @forelse($items as $item)
            <article class="admin-review-card form-stack"><h2>Test attempt #{{ $item->id }}</h2><p>Campaign #{{ $item->campaign_id }} · {{ ucfirst($item->campaign_status) }} · CAD {{ number_format($item->amount_cents / 100, 2) }}</p>
                <p>{{ ucfirst($item->status) }} · Created {{ $item->created_at }}</p>
                @if($item->settled_at)<p>Test settlement {{ $item->settled_at }}</p>@endif
                @foreach($events->get($item->id, collect()) as $event)<p class="field-note">{{ ucfirst($event->outcome) }}: {{ $event->total }} recorded event(s)</p>@endforeach
                @if($item->status === 'paid' && $item->campaign_status !== 'approved')<p class="notice">Test settlement on a non-approved campaign. Review separately; no automatic refund or entitlement.</p>@endif
            </article>
        @empty<p>No test attempts match this filter.</p>@endforelse
        {{ $items->links() }}
    </section>
</x-layouts.admin>
