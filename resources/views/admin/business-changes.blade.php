<x-layouts.admin title="Business change review">
    <div class="page-intro"><div><h1>Branch and contact review</h1><p>Independently verify each proposed address or control of the exact contact email before approving. Reject unverifiable requests.</p></div><a class="button button-outline" href="/admin/business-ownership">Ownership lookup</a></div>
    <form method="get" class="panel form-stack mb-4">
        <div class="form-grid"><label>Status<select name="status">@foreach(['pending','approved','rejected','withdrawn','stale'] as $status)<option value="{{ $status }}" @selected(($filters['status'] ?? 'pending') === $status)>{{ ucfirst($status) }}</option>@endforeach</select></label>
            <label>Kind<select name="kind"><option value="">All requests</option>@foreach(['branch-add','branch-address','branch-close','branch-reopen','branch-relocate','contact'] as $kind)<option value="{{ $kind }}" @selected(($filters['kind'] ?? '') === $kind)>{{ ucfirst(str_replace('-', ' ', $kind)) }}</option>@endforeach</select></label>
            <label>Reference<input type="number" min="1" name="reference" value="{{ $filters['reference'] ?? '' }}"></label></div>
        <button class="button button-primary">Filter requests</button>
    </form>
    <section class="panel form-stack">@forelse($items as $item)
        <article class="admin-review-card form-stack"><h2>{{ $item->business_name }}</h2><p>{{ ucfirst(str_replace('-', ' ', $item->kind)) }} · {{ $item->location_name ?? 'Business-wide' }} · Request #{{ $item->id }} · {{ ucfirst($item->status) }}</p>
            @if($item->previous_value)<p>Previous: {{ $item->previous_value }}</p>@endif<p>Requested: {{ $item->value }}</p><p>Account #{{ $item->requester_id }}</p>
            @if($item->status === 'pending')
            <form class="form-stack" method="post" action="/admin/business-changes/{{ $item->id }}">@csrf
                <label>Private verification notes<textarea name="note" minlength="10" maxlength="1000" required></textarea></label>
                <div class="inline-actions"><button name="decision" value="approved" class="button button-primary">Approve verified request</button><button name="decision" value="rejected" class="button button-outline">Reject</button></div>
            </form>
            @else<p class="field-note">This request is no longer open.</p>@endif
        </article>
    @empty<p>No business changes await independent review.</p>@endforelse{{ $items->links() }}</section>
</x-layouts.admin>
