<x-layouts.site title="My ownership disputes">
    <div class="container page-shell listing-editor">
        <div class="page-intro"><div><a class="text-link" href="/account/businesses">My businesses</a><h1>My ownership disputes</h1><p>Your private submissions and review status.</p></div></div>
        <form method="get" action="/account/business-disputes" class="panel form-stack mb-4">
            <div class="form-grid"><label>Status<select name="status"><option value="">All statuses</option>@foreach(['pending','escalated','closed','withdrawn'] as $status)<option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ ucfirst($status) }}</option>@endforeach</select></label><label>Reference<input name="reference" type="number" min="1" value="{{ $filters['reference'] ?? '' }}"></label></div>
            <div class="inline-actions"><button class="button button-primary">Filter disputes</button><a class="button button-outline" href="/account/business-disputes">Reset</a></div>
        </form>
        <p class="field-note">{{ $items->total() }} matching disputes</p>
        <section class="panel form-stack">@forelse($items as $item)
            <article class="admin-review-card form-stack"><h2>{{ $item->business_name }}</h2><p>{{ ucfirst($item->status) }} · Reference #{{ $item->id }}</p><p class="prose-text">{{ $item->explanation }}</p>
                @if($item->status === 'escalated')<p>Further independent verification is required. Contact support with the reference number.</p>@endif
                @if(in_array($item->status, ['pending','escalated'], true))<form method="post" action="/account/business-disputes/{{ $item->id }}/withdraw">@csrf<button class="button button-outline">Withdraw dispute</button></form>@endif
            </article>
        @empty<p>{{ array_filter($filters) ? 'No disputes match these filters.' : 'No ownership disputes submitted.' }}</p>@endforelse{{ $items->links() }}</section>
    </div>
</x-layouts.site>
