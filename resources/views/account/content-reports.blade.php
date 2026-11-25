<x-layouts.site title="My content reports">
    <div class="container page-shell listing-editor">
        <div class="page-intro"><div><a class="text-link" href="/account/settings">Account settings</a><h1>My content reports</h1><p>Discussion, event, job and business reports you submitted.</p></div><a class="button button-outline" href="/account/listing-reports">Listing reports</a></div>
        <section class="panel">@forelse($items as $item)<article class="admin-review-card form-stack"><h2>{{ \App\Support\ContentReports::TYPES[$item->target_type] }} #{{ $item->target_id }}</h2><p>{{ \App\Support\ContentReports::REASONS[$item->reason] }} · {{ $item->status === 'pending' ? 'Awaiting review' : 'Review completed' }}</p><p class="prose-text">{{ $item->explanation }}</p><p class="field-note">Individual moderation decisions and internal reviewer notes remain private.</p></article>@empty<p>No content reports submitted on this portal.</p>@endforelse{{ $items->links() }}</section>
    </div>
</x-layouts.site>
