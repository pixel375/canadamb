<x-layouts.admin title="Business ownership">
    <div class="page-intro"><div><h1>Business ownership</h1><p>Read-only lookup of current ownership and team roles. Ownership disputes require independent verification.</p></div><a class="button button-outline" href="/admin/business-review">Business review</a></div>
    @if(\App\Support\BusinessAccess::enabled())<p class="mb-4"><a class="button button-outline" href="/admin/business-transfers">Review ownership transfers</a></p>@endif
    @if(\App\Support\BusinessDisputes::enabled())<p class="mb-4"><a class="button button-outline" href="/admin/business-disputes">Review ownership disputes</a></p>@endif
    @if(\App\Support\BusinessChanges::enabled())<p class="mb-4"><a class="button button-outline" href="/admin/business-changes">Review branch and contact requests</a></p>@endif
    <form method="get" action="/admin/business-ownership" class="panel saved-search"><label>Business name<input type="search" name="q" maxlength="100" value="{{ $filters['q'] ?? '' }}"></label><label>Ownership<select name="ownership"><option value="">All</option><option value="claimed" @selected(($filters['ownership'] ?? '') === 'claimed')>Has owner</option><option value="unclaimed" @selected(($filters['ownership'] ?? '') === 'unclaimed')>No owner</option><option value="ambiguous" @selected(($filters['ownership'] ?? '') === 'ambiguous')>Multiple owners</option></select></label>
        <label>Profile status<select name="status"><option value="">All statuses</option>@foreach(['active','pending','rejected'] as $status)<option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ ucfirst($status) }}</option>@endforeach</select></label>
        <label>Community<select name="location"><option value="">All communities</option>@foreach($places as $place)<option value="{{ $place->id }}" @selected((int) ($filters['location'] ?? 0) === $place->id)>{{ $place->name }}</option>@endforeach</select></label>
        <label>Business reference<input type="number" name="reference" min="1" value="{{ $filters['reference'] ?? '' }}"></label>
        <label>Member account reference<input type="number" name="member" min="1" value="{{ $filters['member'] ?? '' }}"></label><button class="button button-primary">Search</button><a class="text-link" href="/admin/business-ownership">Reset</a></form>
    <p class="field-note">{{ $items->total() }} business profiles found</p>
    <section class="panel">
        @forelse($items as $item)
            <article class="admin-review-card form-stack"><h2>{{ $item->name }}</h2><p class="field-note">Business #{{ $item->id }}</p>
                @if($item->members->where('pivot.role', 'owner')->count() > 1)<p class="notice">Multiple owner records. Independent investigation required; no ownership changes are available here.</p>@endif
                <p>{{ ucfirst($item->status) }} · {{ $item->locations->pluck('location.name')->unique()->implode(', ') }}</p>
                @forelse($item->members as $member)<div class="item-row"><div><strong>{{ $member->name }}</strong><p><a class="text-link" href="/admin/business-ownership?member={{ $member->id }}">Account #{{ $member->id }}</a></p></div><span>{{ ucfirst($member->pivot->role) }}</span></div>@empty<p>No team memberships.</p>@endforelse
            </article>
        @empty<p>No matching businesses.</p>@endforelse
        {{ $items->links() }}
    </section>
</x-layouts.admin>
