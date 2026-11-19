<x-layouts.admin title="Business review">
    <div class="page-intro"><div><h1>Business review</h1><p>Review directory submissions, ownership claims and business updates.</p></div></div>
    <section class="panel">
        <h2>Profile submissions</h2>
        @forelse($businesses as $item)
            <article class="form-stack border-b border-gray-300 py-4"><h3>{{ $item->name }}</h3>
                @foreach($item->locations as $branch)<p>{{ $branch->location->name }} · {{ $branch->address }}</p>@endforeach
                <p>{{ $item->description }}</p><x-review-decision :action="'/admin/business-review/'.$item->id"/>
            </article>
        @empty<p>No profiles waiting for review.</p>@endforelse
        {{ $businesses->links() }}
    </section>
    <section class="panel">
        <h2>Ownership claims</h2>
        @forelse($claims as $claim)
            <article class="form-stack border-b border-gray-300 py-4"><div><h3>{{ $claim->name }}</h3><p>Account email · {{ $claim->email }}</p><p>Business contact · {{ $claim->contact_email }}</p></div>
                <p class="prose-text">{{ $claim->evidence }}</p>
                <p>Confirm ownership independently before approving.</p>
                <x-review-decision :action="'/admin/business-claims/'.$claim->id"/>
            </article>
        @empty<p>No claims waiting for review.</p>@endforelse
        {{ $claims->links() }}
    </section>
    <section class="panel">
        <h2>Business updates</h2>
        @forelse($updates as $update)
            <article class="form-stack border-b border-gray-300 py-4"><div><h3>{{ $update->title }}</h3><p>{{ $update->branch->business->name }} · {{ ucfirst($update->type) }}</p></div>
                <p class="prose-text">{{ $update->body }}</p><x-review-decision :action="'/admin/business-updates/'.$update->id"/>
            </article>
        @empty<p>No updates waiting for review.</p>@endforelse
        {{ $updates->links() }}
    </section>
    <section class="panel table-wrap">
        <h2>Import history</h2>
        <table><thead><tr><th>Source</th><th>Status</th><th>Added</th><th>Skipped</th><th>Detail</th></tr></thead><tbody>
            @forelse($imports as $import)
                <tr><td>{{ $import->name }}</td><td>{{ ucfirst($import->status) }}</td><td>{{ $import->created }}</td><td>{{ $import->skipped }}</td><td>{{ $import->error ?? $import->created_at }}</td></tr>
            @empty<tr><td colspan="5">No business imports yet.</td></tr>@endforelse
        </tbody></table>
        {{ $imports->links() }}
    </section>
</x-layouts.admin>
