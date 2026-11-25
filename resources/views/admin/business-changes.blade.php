<x-layouts.admin title="Business change review">
    <div class="page-intro"><div><h1>Branch and contact review</h1><p>Independently verify each proposed address or control of the exact contact email before approving. Reject unverifiable requests.</p></div><a class="button button-outline" href="/admin/business-ownership">Ownership lookup</a></div>
    <section class="panel form-stack">@forelse($items as $item)
        <article class="admin-review-card form-stack"><h2>{{ $item->business_name }}</h2><p>{{ ucfirst(str_replace('-', ' ', $item->kind)) }} · {{ $item->location_name ?? 'Business-wide' }} · Request #{{ $item->id }}</p>
            @if($item->previous_value)<p>Previous: {{ $item->previous_value }}</p>@endif<p>Requested: {{ $item->value }}</p><p>Account #{{ $item->requester_id }}</p>
            <form class="form-stack" method="post" action="/admin/business-changes/{{ $item->id }}">@csrf
                <label>Private verification notes<textarea name="note" minlength="10" maxlength="1000" required></textarea></label>
                <div class="inline-actions"><button name="decision" value="approved" class="button button-primary">Approve verified request</button><button name="decision" value="rejected" class="button button-outline">Reject</button></div>
            </form>
        </article>
    @empty<p>No business changes await independent review.</p>@endforelse{{ $items->links() }}</section>
</x-layouts.admin>
