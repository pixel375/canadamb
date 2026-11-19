<x-layouts.site title="Business updates">
    <div class="container page-shell narrow">
        <div class="page-intro"><div><h1>Business updates</h1><p>Local announcements, promotions and news from businesses.</p></div></div>
        <div class="panel">
            @forelse($updates as $update)
                <article class="item-row"><div><p class="section-label">{{ ucfirst($update->type) }} · {{ $update->published_at->format('M j, Y') }}</p><h3>{{ $update->title }}</h3><a class="text-link" href="/businesses/{{ $update->branch->business->slug }}">{{ $update->branch->business->name }}</a><p class="prose-text">{{ $update->body }}</p></div></article>
            @empty<p>No published business updates yet.</p>@endforelse
        </div>
        {{ $updates->links() }}
    </div>
</x-layouts.site>
