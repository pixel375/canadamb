<x-layouts.admin title="Ownership dispute review">
    <div class="page-intro"><div><h1>Ownership dispute review</h1><p>Private intake and independent triage. Neither decision changes team membership or awards ownership.</p></div><a class="button button-outline" href="/admin/business-ownership">Ownership lookup</a></div>
    <section class="panel form-stack">@forelse($items as $item)
        <article class="admin-review-card form-stack"><h2>{{ $item->business_name }}</h2><p>Reference #{{ $item->id }} · Account #{{ $item->claimant_id }}</p><p>{{ $item->contact_email }}</p><p class="prose-text">{{ $item->explanation }}</p>
            <form method="post" action="/admin/business-disputes/{{ $item->id }}" class="form-stack">@csrf
                <label>Internal review note<textarea name="note" minlength="10" maxlength="1000" required></textarea></label>
                <div class="inline-actions"><button class="button button-primary" name="decision" value="escalated">Escalate for verification</button><button class="button button-outline" name="decision" value="closed">Close dispute</button></div>
            </form>
        </article>
    @empty<p>No disputes await independent review.</p>@endforelse{{ $items->links() }}</section>
</x-layouts.admin>
