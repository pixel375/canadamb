<x-layouts.site title="My businesses">
    <div class="container page-shell">
        <div class="page-intro"><div><h1>My businesses</h1><a class="text-link mt-2" href="/account/listings">My account</a></div><a class="button button-primary" href="/account/businesses/new">Add a business</a></div>
        <p><a class="button button-outline" href="/account/business-invitations">Review business team invitations</a></p>
        <div class="panel">
            <h2>Profiles</h2>
            @forelse($businesses as $business)
                <article class="item-row"><div><h3>{{ $business->name }}</h3><p>{{ ucfirst($business->status) }}</p></div><a class="button button-outline" href="/account/businesses/{{ $business->id }}">Manage profile</a></article>
            @empty<p>You do not manage any business profiles yet.</p>@endforelse
            {{ $businesses->links() }}
        </div>
        @if($claims->isNotEmpty())
        <div class="panel"><h2>Claim requests</h2>
            @foreach($claims as $claim)<div class="item-row"><div><h3>{{ $claim->name }}</h3><p>{{ ucfirst($claim->status) }}</p></div>
                <a class="button button-outline" href="/account/business-claims/{{ $claim->id }}">View claim</a></div>@endforeach
        </div>
        @endif
    </div>
</x-layouts.site>
