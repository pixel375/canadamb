<x-layouts.site title="My content reports">
    <div class="container page-shell listing-editor">
        <div class="page-intro"><div><a class="text-link" href="/account/settings">Account settings</a><h1>My content reports</h1><p>Track reports you submitted on this portal.</p></div><a class="button button-outline" href="/account/listing-reports">Listing reports</a></div>
        <form method="get" action="/account/content-reports" class="panel form-stack mb-4">
            <p>{{ $counts['pending'] ?? 0 }} awaiting review · {{ $counts['resolved'] ?? 0 }} completed</p>
            <div class="form-grid">
                <label>Status<select name="status"><option value="">All statuses</option><option value="pending" @selected(($filters['status'] ?? '') === 'pending')>Awaiting review</option><option value="resolved" @selected(($filters['status'] ?? '') === 'resolved')>Review completed</option></select></label>
                <label>Content type<select name="type"><option value="">All types</option>@foreach(\App\Support\ContentReports::TYPES as $key => $label)<option value="{{ $key }}" @selected(($filters['type'] ?? '') === $key)>{{ $label }}</option>@endforeach</select></label>
                <label>Report reference<input type="number" name="reference" min="1" value="{{ $filters['reference'] ?? '' }}"></label>
            </div>
            <div class="inline-actions"><button class="button button-primary">Filter reports</button><a class="button button-outline" href="/account/content-reports">Reset</a></div>
        </form>
        <section class="panel">
            @forelse($items as $item)
                <article class="admin-review-card form-stack">
                    <h2>{{ \App\Support\ContentReports::TYPES[$item->target_type] }} #{{ $item->target_id }}</h2>
                    <p>{{ \App\Support\ContentReports::REASONS[$item->reason] }} · {{ $item->status === 'pending' ? 'Awaiting review' : 'Review completed' }}</p>
                    <p class="field-note">Report #{{ $item->id }} · Submitted {{ \Illuminate\Support\Carbon::parse($item->created_at)->timezone('America/Winnipeg')->format('M j, Y') }}</p>
                    @if($item->resolved_at)
                        <p class="field-note">Reviewed {{ \Illuminate\Support\Carbon::parse($item->resolved_at)->timezone('America/Winnipeg')->format('M j, Y') }}</p>
                    @endif
                    <p class="prose-text">{{ $item->explanation }}</p><p class="field-note">Individual moderation decisions and internal reviewer notes remain private.</p>
                </article>
            @empty
                @if(array_filter($filters))
                    <p>No reports match these filters.</p>
                @else
                    <p>No content reports submitted on this portal.</p>
                @endif
            @endforelse
            {{ $items->links() }}
        </section>
    </div>
</x-layouts.site>
