<x-layouts.admin title="Business Pro trial records">
    <div class="page-intro"><div><h1>Business Pro trial records</h1><p>Internal, time-limited trial records only. No paid functionality, subscription renewal or billing.</p></div><a class="button button-outline" href="/admin/commercial">Commercial planning</a></div>
    <section class="panel form-stack"><h2>Grant a trial</h2><form method="post" action="/admin/entitlements" class="form-stack">@csrf
        <label>Business<select name="business_id" required><option value="">Choose an active business</option>@foreach($businesses as $business)<option value="{{ $business->id }}" @selected((int) old('business_id') === $business->id)>{{ $business->name }}</option>@endforeach</select></label>
        <label>Days<input name="days" type="number" min="1" max="90" value="{{ old('days', 30) }}" required></label><label>Internal reason<textarea name="note" required minlength="8" maxlength="400">{{ old('note') }}</textarea></label>
        <button class="button button-primary">Grant internal trial</button>
    </form></section>
    <section class="panel form-stack"><h2>Trial history</h2>@forelse($items as $item)<article class="admin-review-card form-stack"><h3>{{ $item->name }}</h3><p>{{ $item->starts_on }} to {{ $item->ends_on }} (end exclusive)</p><p>{{ $item->revoked_at ? 'Revoked' : ($item->ends_on <= $today ? 'Expired' : 'Active') }}</p>@if(!$item->revoked_at && $item->ends_on > $today)<details><summary>Revoke trial</summary><form method="post" action="/admin/entitlements/{{ $item->id }}/revoke">@csrf<button class="button button-outline mt-2">Confirm revoke</button></form></details>@endif</article>@empty<p>No trial records.</p>@endforelse{{ $items->links() }}</section>
</x-layouts.admin>
