<x-layouts.admin title="Privacy request review">
    <div class="page-intro"><div><h1>Privacy requests</h1><p>Restricted intake queue. Review identity, retention and shared records before arranging a lawful response. This page never deletes accounts or marks deletion complete.</p></div></div>
    <form class="panel form-stack mb-4" method="get">
        <div class="form-grid"><label>Status<select name="status"><option value="">Open requests</option>@foreach(['pending','in-review','withdrawn'] as $state)<option value="{{ $state }}" @selected(($filters['status'] ?? '') === $state)>{{ ucfirst(str_replace('-', ' ', $state)) }}</option>@endforeach</select></label>
        <label>Kind<select name="kind"><option value="">All types</option><option value="deletion" @selected(($filters['kind'] ?? '') === 'deletion')>Account deletion</option><option value="data-review" @selected(($filters['kind'] ?? '') === 'data-review')>Data review</option></select></label>
        <label>Reference number<input type="number" name="reference" min="1" value="{{ $filters['reference'] ?? '' }}"></label></div>
        <button class="button button-primary">Filter requests</button>
    </form>
    @if($inspection)
        <section class="panel form-stack mb-4"><h2>Record impact · request #{{ $inspection['reference'] }}</h2>
            <p class="field-note">Read-only current counts, not an erasure plan or proof of completeness. No private contents, tokens or filenames are displayed. Review shared records, retention and backups separately.</p>
            @foreach($inspection['counts'] as $label => $count)<div class="item-row"><span>{{ $label }}</span><strong>{{ $count ?? 'Not installed' }}</strong></div>@endforeach
            <a class="text-link" href="/admin/privacy-requests">Close inspection</a>
        </section>
    @endif
    <section class="panel form-stack">@forelse($items as $item)
        <article class="admin-review-card form-stack"><h2>{{ $item->kind === 'deletion' ? 'Account deletion' : 'Data review' }}</h2><p>Reference #{{ $item->id }} · Account #{{ $item->user_id }} · {{ ucfirst(str_replace('-', ' ', $item->status)) }}</p><p class="field-note">Submitted {{ \Illuminate\Support\Carbon::parse($item->created_at, config('app.timezone'))->timezone('America/Winnipeg')->format('M j, Y · g:i a T') }} · Updated {{ \Illuminate\Support\Carbon::parse($item->updated_at, config('app.timezone'))->timezone('America/Winnipeg')->format('M j, Y · g:i a T') }}</p>
            @if(in_array($item->status, ['pending','in-review'], true))
            <a class="button button-outline" href="/admin/privacy-requests?inspect={{ $item->id }}&reference={{ $item->id }}">Inspect record impact</a>
            <form class="form-stack" method="post" action="/admin/privacy-requests/{{ $item->id }}">@csrf<input type="hidden" name="version" value="{{ \App\Support\PrivacyRequests::reviewVersion($item) }}">
                @if($item->reviewer_id)<p class="field-note">Last reviewer: account #{{ $item->reviewer_id }}</p>@endif
                <label>Internal review note<textarea name="note" minlength="10" maxlength="1000" required>{{ $item->review_note }}</textarea></label>
                <p class="field-note">Record only necessary review information; do not paste passwords or private conversation contents.</p>
                <x-password-field name="current_password" label="Current password" autocomplete="current-password" :field-id="'privacy-review-password-'.$item->id"/>
                <button class="button button-primary">Record review progress</button>
            </form>
            @else<p class="field-note">Withdrawn by the member. No further review action is available.</p>@endif
        </article>
    @empty<p>No eligible privacy requests.</p>@endforelse{{ $items->links() }}</section>
</x-layouts.admin>
