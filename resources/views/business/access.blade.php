<x-layouts.site title="Business access requests">
    <div class="container page-shell listing-editor">
        <div class="page-intro"><div><a class="text-link" href="/account/businesses">My businesses</a><h1>Invitations and ownership transfers</h1><p>Accept only requests you recognize. No access is granted by an invitation alone.</p></div></div>
        <section class="panel form-stack">@forelse($items as $item)
            @php($expired = in_array($item->status, ['pending','accepted'], true) && $item->expires_at <= now()->toDateTimeString())
            <article class="admin-review-card form-stack"><h2>{{ $item->business_name }}</h2><p>{{ ucfirst($item->kind) }} · {{ $expired ? 'Expired' : ucfirst($item->status) }} · {{ (int) $item->recipient_id === auth()->id() ? 'Received' : 'Sent' }}</p>
                @if(!$expired && $item->status === 'pending' && (int) $item->recipient_id === auth()->id())
                    <form method="post" action="/account/business-access/{{ $item->id }}/respond" class="form-stack">@csrf
                        <p class="field-note">{{ $item->kind === 'transfer' ? 'Acceptance requests independent review. You will not become owner until approved.' : 'Acceptance grants the indicated team role.' }}</p>
                        <label>Current password<input type="password" name="current_password" required maxlength="72" autocomplete="current-password"></label>
                        <div class="inline-actions"><button class="button button-primary" name="action" value="accept">Accept</button><button class="button button-outline" name="action" value="decline">Decline</button></div>
                    </form>
                @endif
                @if(!$expired && in_array($item->status, ['pending','accepted'], true))<form method="post" action="/account/business-access/{{ $item->id }}/cancel">@csrf<button class="button button-outline">Cancel request</button></form>@endif
            </article>
        @empty<p>No access requests yet.</p>@endforelse{{ $items->links() }}</section>
    </div>
</x-layouts.site>
