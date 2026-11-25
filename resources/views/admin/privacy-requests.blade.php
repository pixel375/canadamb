<x-layouts.admin title="Privacy request review">
    <div class="page-intro"><div><h1>Privacy requests</h1><p>Restricted intake queue. Review identity, retention and shared records before arranging a lawful response. This page never deletes accounts or marks deletion complete.</p></div></div>
    <section class="panel form-stack">@forelse($items as $item)
        <article class="admin-review-card form-stack"><h2>{{ $item->kind === 'deletion' ? 'Account deletion' : 'Data review' }}</h2><p>Reference #{{ $item->id }} · Account #{{ $item->user_id }} · {{ ucfirst(str_replace('-', ' ', $item->status)) }}</p>
            <form class="form-stack" method="post" action="/admin/privacy-requests/{{ $item->id }}">@csrf
                <label>Internal review note<textarea name="note" minlength="10" maxlength="1000" required>{{ $item->review_note }}</textarea></label>
                <p class="field-note">Record only necessary review information; do not paste passwords or private conversation contents.</p>
                <x-password-field name="current_password" label="Current password" autocomplete="current-password"/>
                <button class="button button-primary">Record review progress</button>
            </form>
        </article>
    @empty<p>No eligible privacy requests.</p>@endforelse{{ $items->links() }}</section>
</x-layouts.admin>
