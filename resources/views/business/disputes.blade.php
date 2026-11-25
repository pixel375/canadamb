<x-layouts.site title="My ownership disputes">
    <div class="container page-shell listing-editor">
        <div class="page-intro"><div><a class="text-link" href="/account/businesses">My businesses</a><h1>My ownership disputes</h1><p>Your private submissions and review status.</p></div></div>
        <section class="panel form-stack">@forelse($items as $item)
            <article class="admin-review-card form-stack"><h2>{{ $item->business_name }}</h2><p>{{ ucfirst($item->status) }} · Reference #{{ $item->id }}</p><p class="prose-text">{{ $item->explanation }}</p>
                @if($item->status === 'escalated')<p>Further independent verification is required. Contact support with the reference number.</p>@endif
                @if(in_array($item->status, ['pending','escalated'], true))<form method="post" action="/account/business-disputes/{{ $item->id }}/withdraw">@csrf<button class="button button-outline">Withdraw dispute</button></form>@endif
            </article>
        @empty<p>No ownership disputes submitted.</p>@endforelse{{ $items->links() }}</section>
    </div>
</x-layouts.site>
