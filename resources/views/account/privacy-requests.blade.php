<x-layouts.site title="Privacy requests">
    <div class="container page-shell listing-editor">
        <div class="page-intro"><div><a class="text-link" href="/account/settings">Account settings</a><h1>Privacy requests</h1><p>Submit or withdraw a private request for account deletion or a more complete data review.</p></div></div>
        <section class="panel form-stack"><h2>New privacy request</h2><p>This records your request for review. It does not immediately delete or disable your account. Review must consider shared conversations, business ownership and necessary retained records.</p>
            <a class="text-link" href="/account/data-export">Download your available data first</a>
            <form class="form-stack" method="post" action="/account/privacy-requests">@csrf
                <label>Request type<select name="kind"><option value="deletion">Account deletion</option><option value="data-review">More complete access to my data</option></select></label>
                <label class="checkbox"><input type="checkbox" name="confirm_request" value="1" required> I understand this submits a request, not immediate deletion.</label>
                <x-password-field name="current_password" label="Current password" autocomplete="current-password"/>
                <button class="button button-primary">Submit private request</button>
            </form>
        </section>
        <section class="panel form-stack"><h2>Request history</h2><p>{{ $openCount }} open {{ \Illuminate\Support\Str::plural('request', $openCount) }} across your account.</p>
            <form method="get" action="/account/privacy-requests" class="form-stack">
                <div class="form-grid"><label>Status<select name="status"><option value="">All statuses</option>@foreach(['pending', 'in-review', 'withdrawn'] as $state)<option value="{{ $state }}" @selected(($filters['status'] ?? '') === $state)>{{ ucfirst(str_replace('-', ' ', $state)) }}</option>@endforeach</select></label>
                <label>Request type<select name="kind"><option value="">All types</option><option value="deletion" @selected(($filters['kind'] ?? '') === 'deletion')>Account deletion</option><option value="data-review" @selected(($filters['kind'] ?? '') === 'data-review')>Data review</option></select></label></div>
                <div class="inline-actions"><button class="button button-primary">Filter history</button><a class="button button-outline" href="/account/privacy-requests">Reset filters</a></div>
            </form>
            @forelse($items as $item)
            <article class="admin-review-card form-stack"><h3>{{ $item->kind === 'deletion' ? 'Account deletion' : 'Data review' }}</h3><p>Reference #{{ $item->id }} · {{ ucfirst(str_replace('-', ' ', $item->status)) }}</p><p class="field-note">Submitted {{ \Illuminate\Support\Carbon::parse($item->created_at, config('app.timezone'))->timezone('America/Winnipeg')->format('M j, Y · g:i a T') }} · Updated {{ \Illuminate\Support\Carbon::parse($item->updated_at, config('app.timezone'))->timezone('America/Winnipeg')->format('M j, Y · g:i a T') }}</p>
                @if(in_array($item->status, ['pending', 'in-review'], true))<details><summary>Withdraw request</summary><form class="form-stack mt-2" method="post" action="/account/privacy-requests/{{ $item->id }}/withdraw">@csrf
                    <x-password-field name="current_password" label="Current password" autocomplete="current-password" :field-id="'privacy-withdraw-password-'.$item->id"/><button class="button button-outline">Confirm withdrawal</button>
                </form></details>@endif
            </article>
        @empty<p>{{ filled($filters['status'] ?? null) || filled($filters['kind'] ?? null) ? 'No requests match these filters.' : 'No privacy requests.' }}</p>@endforelse{{ $items->links() }}</section>
        <a class="text-link" href="/contact?topic=privacy">Privacy support</a>
    </div>
</x-layouts.site>
