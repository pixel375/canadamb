<x-layouts.admin title="Business ownership">
    <div class="page-intro"><div><h1>Business ownership</h1><p>Read-only lookup of current ownership and team roles. Ownership disputes require independent verification.</p></div><a class="button button-outline" href="/admin/business-review">Business review</a></div>
    @if(\App\Support\BusinessAccess::enabled())<p class="mb-4"><a class="button button-outline" href="/admin/business-transfers">Review ownership transfers</a></p>@endif
    @if(\App\Support\BusinessDisputes::enabled())<p class="mb-4"><a class="button button-outline" href="/admin/business-disputes">Review ownership disputes</a></p>@endif
    <form method="get" action="/admin/business-ownership" class="panel saved-search"><label>Business name<input type="search" name="q" maxlength="100" value="{{ $filters['q'] ?? '' }}"></label><label>Ownership<select name="ownership"><option value="">All</option><option value="claimed" @selected(($filters['ownership'] ?? '') === 'claimed')>Has owner</option><option value="unclaimed" @selected(($filters['ownership'] ?? '') === 'unclaimed')>No owner</option></select></label><button class="button button-primary">Search</button><a class="text-link" href="/admin/business-ownership">Reset</a></form>
    <p class="field-note">{{ $items->total() }} business profiles found</p>
    <section class="panel">
        @forelse($items as $item)
            <article class="admin-review-card form-stack"><h2>{{ $item->name }}</h2><p>{{ ucfirst($item->status) }} · {{ $item->locations->pluck('location.name')->unique()->implode(', ') }}</p>
                @forelse($item->members as $member)<div class="item-row"><div><strong>{{ $member->name }}</strong><p>Account #{{ $member->id }}</p></div><span>{{ ucfirst($member->pivot->role) }}</span></div>@empty<p>No team memberships.</p>@endforelse
            </article>
        @empty<p>No matching businesses.</p>@endforelse
        {{ $items->links() }}
    </section>
</x-layouts.admin>
