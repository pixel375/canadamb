<x-layouts.site title="Business updates" :location="$place">
    <div class="container page-shell narrow">
        <div class="page-intro"><div><h1>Business updates</h1><p>Local announcements, promotions and news from businesses in {{ $place?->name ?? 'Manitoba' }}.</p></div></div>
        @if($places->isNotEmpty())
            <form class="panel filter-bar mb-4" action="/business-updates" method="get">
            @if($places->isNotEmpty())<label>Location<select name="location"><option value="">All Manitoba</option>@foreach($places as $option)<option value="{{ $option->slug }}" @selected($place?->id === $option->id)>{{ $option->name }}</option>@endforeach</select></label>@endif
                <button class="button button-primary" type="submit">Filter updates</button>
            </form>
        @endif
        <div class="panel">
            @forelse($updates as $update)
                <article class="item-row"><div><p class="section-label">{{ ucfirst($update->type) }} · {{ $update->published_at->format('M j, Y') }}</p><h3>{{ $update->title }}</h3><a class="text-link" href="/businesses/{{ $update->branch->business->slug }}">{{ $update->branch->business->name }}</a><p class="prose-text">{{ $update->body }}</p></div></article>
            @empty<p>No published business updates yet.</p>@endforelse
        </div>
        {{ $updates->links() }}
    </div>
</x-layouts.site>
