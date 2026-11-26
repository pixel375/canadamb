<x-layouts.admin title="Business Pro trial records">
    <div class="page-intro"><div><h1>Business Pro trial records</h1><p>Internal, time-limited trial records only. No paid functionality, subscription renewal or billing.</p></div><a class="button button-outline" href="/admin/commercial">Commercial planning</a></div>
    <section class="panel form-stack"><h2>Grant a trial</h2>
        <form method="get" action="/admin/entitlements" class="inline-actions">
            @foreach(['status','q'] as $field)
                @if(filled($filters[$field] ?? null))<input type="hidden" name="{{ $field }}" value="{{ $filters[$field] }}">@endif
            @endforeach
            <label>Find eligible business<input name="business_q" type="search" maxlength="100" value="{{ $filters['business_q'] ?? '' }}"></label><button class="button button-outline">Search businesses</button>
        </form>
        <p class="field-note">{{ $businesses->total() }} eligible businesses</p>
        {{ $businesses->links() }}
        <form method="post" action="/admin/entitlements" class="form-stack">@csrf
        <label>Business<select name="business_id" required><option value="">Choose an active business</option>@foreach($businesses as $business)<option value="{{ $business->id }}" @selected((int) old('business_id') === $business->id)>{{ $business->name }}</option>@endforeach</select></label>
        <label>Days<input name="days" type="number" min="1" max="90" value="{{ old('days', 30) }}" required></label><label>Internal reason<textarea name="note" required minlength="8" maxlength="400">{{ old('note') }}</textarea></label>
        <button class="button button-primary">Grant internal trial</button>
    </form></section>
    <section class="panel form-stack"><h2>Trial history</h2>
        <form method="get" action="/admin/entitlements" class="form-stack">
            @if(filled($filters['business_q'] ?? null))<input type="hidden" name="business_q" value="{{ $filters['business_q'] }}">@endif
            <div class="form-grid"><label>Business name<input name="q" type="search" maxlength="100" value="{{ $filters['q'] ?? '' }}"></label><label>Status<select name="status"><option value="">All statuses</option>@foreach(['active','expired','scheduled','revoked'] as $status)<option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ ucfirst($status) }}</option>@endforeach</select></label></div>
            <div class="inline-actions"><button class="button button-primary">Filter trials</button><a class="button button-outline" href="/admin/entitlements">Reset</a></div>
        </form>
        <p class="field-note">{{ $items->total() }} matching trials</p>
        @forelse($items as $item)<article class="admin-review-card form-stack"><h3>{{ $item->name }}</h3><p>{{ $item->starts_on }} to {{ $item->ends_on }} (end exclusive)</p><p>{{ $item->revoked_at ? 'Revoked' : ($item->ends_on <= $today ? 'Expired' : ($item->starts_on > $today ? 'Scheduled' : 'Active')) }}</p>@if(!$item->revoked_at && $item->ends_on > $today)<details><summary>Revoke trial</summary><form method="post" action="/admin/entitlements/{{ $item->id }}/revoke">@csrf<button class="button button-outline mt-2">Confirm revoke</button></form></details>@endif</article>@empty<p>No trial records match these filters.</p>@endforelse{{ $items->links() }}</section>
</x-layouts.admin>
