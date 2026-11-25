<x-layouts.admin title="Business transfer review">
    <div class="page-intro"><div><h1>Ownership transfer review</h1><p>Verify actual authority and both parties independently before approving. Approval removes the former owner from the team.</p></div><a class="button button-outline" href="/admin/business-ownership">Ownership lookup</a></div>
    <section class="panel form-stack">@forelse($items as $item)
        <article class="admin-review-card form-stack"><h2>{{ $item->business_name }}</h2><p>Request #{{ $item->id }} · Account #{{ $item->requester_id }} to #{{ $item->recipient_id }}</p>
            <form method="post" action="/admin/business-transfers/{{ $item->id }}" class="form-stack">@csrf
                <label>Verification notes<textarea name="note" minlength="10" maxlength="1000" required></textarea></label>
                <label>Current password<input name="current_password" type="password" required maxlength="72" autocomplete="current-password"></label>
                <div class="inline-actions"><button class="button button-primary" name="decision" value="approve">Approve transfer</button><button class="button button-outline" name="decision" value="reject">Reject transfer</button></div>
            </form>
        </article>
    @empty<p>No eligible transfers await review.</p>@endforelse{{ $items->links() }}</section>
</x-layouts.admin>
