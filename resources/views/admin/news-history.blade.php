<x-layouts.admin title="Editorial revision history">
    <div class="page-intro"><div><a class="text-link" href="/admin/news">Editorial queue</a><h1>Editorial revision history</h1><p>{{ $item->title }} · Reference #{{ $item->id }} · {{ ucfirst($item->status) }}</p></div></div>
    <p class="notice">Read-only snapshots of the content before each editorial change. Nothing on this page restores or republishes an article.</p>
    <section class="panel form-stack">
        @forelse($revisions as $revision)
            <details class="admin-review-card"><summary>Revision #{{ $revision->id }} · {{ \Illuminate\Support\Carbon::parse($revision->created_at)->timezone('America/Winnipeg')->format('M j, Y · g:i a') }} · {{ ucfirst($revision->summary['status'] ?? 'Unknown') }}</summary>
                <h2>{{ $revision->summary['title'] ?? 'Unavailable headline' }}</h2>
                <p class="prose-text">{{ $revision->summary['body'] ?? '' }}</p>
                @if(filled($revision->summary['verification'] ?? null))<p class="field-note">Prior verification notes: {{ $revision->summary['verification'] }}</p>@endif
            </details>
        @empty<p>No previous revisions recorded.</p>@endforelse
        {{ $revisions->links() }}
    </section>
</x-layouts.admin>
