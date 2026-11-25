<x-layouts.admin title="Business review">
    <div class="page-intro"><div><h1>Business review</h1><p>Review directory submissions, ownership claims and business updates.</p></div><a class="button button-outline" href="/admin/business-ownership">Ownership lookup</a></div>
    <form method="get" action="/admin/business-review" class="panel form-stack mb-4">
        <div class="form-grid"><label>Queue<select name="history"><option value="pending" @selected(!$history)>Pending reviews</option><option value="completed" @selected($history)>Completed records</option></select></label><label>Community<select name="location"><option value="">All communities</option>@foreach($places as $place)<option value="{{ $place->id }}" @selected((int) ($filters['location'] ?? 0) === $place->id)>{{ $place->name }}</option>@endforeach</select></label><label>Search<input type="search" name="q" maxlength="100" value="{{ $filters['q'] ?? '' }}" placeholder="Business or update"></label></div>
        <div class="inline-actions"><button class="button button-primary">Filter reviews</button><a class="button button-outline" href="/admin/business-review">Reset</a></div>
    </form>
    <nav class="inline-actions admin-jump-links" aria-label="Business review sections"><a class="button button-outline" href="#profiles">Profiles ({{ $businesses->total() }})</a><a class="button button-outline" href="#claims">Claims ({{ $claims->total() }})</a><a class="button button-outline" href="#updates">Updates ({{ $updates->total() }})</a><a class="button button-outline" href="#imports">Imports</a></nav>
    <section class="panel" id="profiles">
        <h2>Profile submissions</h2>
        @forelse($businesses as $item)
            <article class="admin-review-card form-stack"><h3>{{ $item->name }}</h3>
                @foreach($item->locations as $branch)<p>{{ $branch->location->name }} · {{ $branch->address }}</p>@endforeach
                <p>{{ $item->description }}</p>
                @if($history)
                    <p class="field-note">{{ ucfirst($item->status) }} · Historical record</p>
                @else
                    <x-review-decision :action="'/admin/business-review/'.$item->id" :version="$item->profileVersion()"/>
                @endif
            </article>
        @empty<p>No profiles match the current review filters.</p>@endforelse
        {{ $businesses->links() }}
    </section>
    <section class="panel" id="claims">
        <h2>Ownership claims</h2>
        @forelse($claims as $claim)
            <article class="admin-review-card form-stack"><div><h3>{{ $claim->name }}</h3>
                @if($history)
                    <p class="field-note">{{ ucfirst($claim->status) }} · Claim #{{ $claim->id }}</p></div>
                @else
                <p>Account email · {{ $claim->email }}</p><p>Business contact · {{ $claim->contact_email }}</p></div>
                <p class="prose-text">{{ $claim->evidence }}</p>
                <p>Confirm ownership independently before approving.</p>
                <x-review-decision :action="'/admin/business-claims/'.$claim->id"/>
                @endif
            </article>
        @empty<p>No claims match the current review filters.</p>@endforelse
        {{ $claims->links() }}
    </section>
    <section class="panel" id="updates">
        <h2>Business updates</h2>
        @forelse($updates as $update)
            <article class="admin-review-card form-stack"><div><h3>{{ $update->title }}</h3><p>{{ $update->branch->business->name }} · {{ $update->branch->location->name }} · {{ ucfirst($update->type) }}</p></div>
                <p class="prose-text">{{ $update->body }}</p>
                @if($history)
                    <p class="field-note">{{ ucfirst($update->status) }} · Historical record</p>
                @else
                    <x-review-decision :action="'/admin/business-updates/'.$update->id" :version="$update->reviewVersion()"/>
                @endif
            </article>
        @empty<p>No updates match the current review filters.</p>@endforelse
        {{ $updates->links() }}
    </section>
    <section class="panel table-wrap" id="imports">
        <h2>Import history</h2>
        <table><thead><tr><th>Source</th><th>Status</th><th>Added</th><th>Skipped</th><th>Detail</th></tr></thead><tbody>
            @forelse($imports as $import)
                <tr><td>{{ $import->name }}</td><td>{{ ucfirst($import->status) }}</td><td>{{ $import->created }}</td><td>{{ $import->skipped }}</td><td>{{ $import->error ?? $import->created_at }}</td></tr>
            @empty<tr><td colspan="5">No business imports yet.</td></tr>@endforelse
        </tbody></table>
        {{ $imports->links() }}
    </section>
</x-layouts.admin>
