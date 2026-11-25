<x-layouts.site :title="$item->name.' advertising'">
    <div class="container page-shell listing-editor">
        <div class="page-intro"><div><a class="text-link" href="/account/businesses/{{ $item->id }}">Business dashboard</a><h1>Advertising requests</h1><p>{{ $item->name }} · Private planning workspace</p></div></div>
        <p class="notice">Campaign requests and approvals are private. No payments, reservations or live advertising are available.</p>
        <section class="panel form-stack"><h2>Business Pro trial</h2><p>{{ $trial ? 'An internal trial record is currently active.' : 'No active trial record.' }} Trial records do not unlock paid features.</p></section>
        <section class="panel form-stack"><h2>Request a campaign</h2><form class="form-stack" method="post" action="/account/businesses/{{ $item->id }}/advertising">@csrf
            <label>Portal<select name="site_id" required><option value="">Choose portal</option>@foreach($portals as $portal)<option value="{{ $portal->id }}" @selected((int) old('site_id') === $portal->id)>{{ $portal->name }}</option>@endforeach</select></label>
            <label>Placement<select name="placement" required>@foreach(\App\Support\CommercialCatalog::PLACEMENTS as $key => $module)<option value="{{ $key }}" @selected(old('placement') === $key)>{{ $catalog[$key]['name'] }} · {{ $catalog[$key]['price_cents'] === null ? 'Unpriced' : 'CAD '.number_format($catalog[$key]['price_cents'] / 100, 2).' / week' }}</option>@endforeach</select></label>
            <label>Proposed headline<input name="title" value="{{ old('title') }}" required maxlength="120"></label>
            <label>Proposed advertisement text<textarea name="body" required maxlength="500" data-character-count>{{ old('body') }}</textarea><small class="field-note" data-character-feedback></small></label>
            <div class="form-grid"><label>Proposed start date<input type="date" name="starts_on" required min="{{ today('America/Winnipeg')->toDateString() }}" value="{{ old('starts_on') }}"></label><label>Weeks<input type="number" name="weeks" min="1" max="12" required value="{{ old('weeks', 1) }}"></label></div>
            <button class="button button-primary">Submit private request</button>
        </form></section>
        <section class="panel form-stack"><h2>My campaign requests</h2><form method="get" class="inline-actions"><label>Status<select name="status"><option value="">All</option>@foreach(['pending','approved','rejected','withdrawn'] as $status)<option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ ucfirst($status) }}</option>@endforeach</select></label><button class="button button-outline">Filter requests</button></form>
            @forelse($campaigns as $campaign)<article class="admin-review-card form-stack"><h3>{{ $campaign->title }}</h3><p>{{ $campaign->site->name }} · {{ $catalog[$campaign->placement]['name'] }} · {{ ucfirst($campaign->status) }} · {{ ucfirst(str_replace('-', ' ', $campaign->planningPhase())) }}</p><p>{{ $campaign->starts_on->format('M j, Y') }} – {{ $campaign->endsOn()->format('M j, Y') }} (end exclusive) · {{ $campaign->weeks }} week(s)</p><p>Planning estimate: {{ $campaign->estimated_cents === null ? 'Unpriced' : 'CAD '.number_format($campaign->estimated_cents / 100, 2) }}</p><p class="prose-text">{{ $campaign->body }}</p>@if($campaign->review_note)<p>Reviewer: {{ $campaign->review_note }}</p>@endif
                @if(in_array($campaign->status, ['pending','approved'], true))<details><summary>Withdraw request</summary><form method="post" action="/account/businesses/{{ $item->id }}/advertising/{{ $campaign->id }}/withdraw">@csrf<button class="button button-outline mt-2">Confirm withdrawal</button></form></details>@endif
            </article>@empty<p>No campaign requests yet.</p>@endforelse
            {{ $campaigns->links() }}
        </section>
    </div>
</x-layouts.site>
