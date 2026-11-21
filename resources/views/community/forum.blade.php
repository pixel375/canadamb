<x-layouts.site title="Discussions">
    <div class="container page-shell narrow">
        <div class="page-intro"><div><h1>Discussions</h1><p>Ask questions, share recommendations, and discuss what matters locally.</p></div><a class="button button-primary" href="/community/new">Start a discussion</a></div>
        <details class="search-disclosure" @if(!empty($filters)) open @endif>
            <summary><x-icon name="search" size="18"/> Search discussions <x-icon class="disclosure-arrow" name="chevron-down" size="17"/></summary>
            <form method="get" action="/community" class="panel filter-bar">
            <label>Search discussions<input type="search" name="q" maxlength="100" value="{{ $filters['q'] ?? '' }}"></label>
            <label>Category<select name="category"><option value="">All categories</option>@foreach(\App\Support\Community::FORUMS as $id => $label)<option value="{{ $id }}" @selected(($filters['category'] ?? null) === $id)>{{ $label }}</option>@endforeach</select></label>
            <button class="button button-primary">Filter</button>
        </form>
        </details>
        <div class="panel">
            @forelse($threads as $thread)
                <article class="item-row"><div>
                    <p class="section-label">{{ \App\Support\Community::FORUMS[$thread->category] }} · {{ $thread->location?->name ?? 'Manitoba' }}</p>
                    <h2><a href="/community/{{ $thread->id }}">{{ $thread->title }}</a></h2>
                    <p>{{ $thread->replies_count }} {{ \Illuminate\Support\Str::plural('reply', $thread->replies_count) }} · Active {{ $thread->last_activity_at->diffForHumans() }}@if($thread->pinned) · Pinned @endif @if($thread->locked) · Locked @endif</p>
                </div></article>
            @empty<p>No discussions match these filters.</p>@endforelse
        </div>
        {{ $threads->links() }}
    </div>
</x-layouts.site>
