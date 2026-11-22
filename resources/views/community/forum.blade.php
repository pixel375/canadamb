@php
    $site = request()->attributes->get('site');
    $area = $site->location?->name ?? 'Manitoba';
    $sort = $filters['sort'] ?? 'activity';
    $filtered = filled($filters['q'] ?? null) || filled($filters['category'] ?? null)
        || filled($filters['location'] ?? null) || $sort === 'newest';
@endphp
<x-layouts.site title="Discussions" :description="'Browse local questions, recommendations, and discussions in '.$area.'.'" :canonical="$canonical">
    <div class="container page-shell narrow">
        <div class="page-intro">
            <div><h1>Discussions</h1><p>Browse questions and recommendations in {{ $area }}.</p></div>
            <div class="inline-actions">
                <a class="button button-primary" href="/discussions/new">Start a discussion</a>
                @if(auth()->user()?->hasVerifiedEmail())
                    <a class="text-link" href="/account/discussions">My discussions</a>
                @endif
            </div>
        </div>
        <details class="search-disclosure" @if($filtered) open @endif>
            <summary><x-icon name="search" size="18"/> Search discussions <x-icon class="disclosure-arrow" name="chevron-down" size="17"/></summary>
            <form method="get" action="/discussions" class="panel filter-bar">
                <label>Discussion title
                    <input type="search" name="q" maxlength="100" value="{{ $filters['q'] ?? '' }}">
                </label>
                <label>Category
                    <select name="category">
                        <option value="">All categories</option>
                        @foreach(\App\Support\Community::FORUMS as $id => $label)
                            <option value="{{ $id }}" @selected(($filters['category'] ?? null) === $id)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                @if($site->isNetwork())
                    <label>Location
                        <select name="location">
                            <option value="">All Manitoba</option>
                            @foreach($places as $place)
                                <option value="{{ $place->id }}" @selected((string) ($filters['location'] ?? '') === (string) $place->id)>{{ $place->name }}</option>
                            @endforeach
                        </select>
                    </label>
                @endif
                <label>Sort by
                    <select name="sort">
                        <option value="activity" @selected($sort === 'activity')>Recent activity</option>
                        <option value="newest" @selected($sort === 'newest')>Newest discussions</option>
                    </select>
                </label>
                <div class="inline-actions">
                    <button class="button button-primary">Apply filters</button>
                    @if($filtered)<a class="text-link" href="/discussions">Clear filters</a>@endif
                </div>
            </form>
        </details>
        <div class="panel discussion-list">
            @forelse($threads as $thread)
                <article class="item-row">
                    <div>
                        <p class="section-label">{{ \App\Support\Community::FORUMS[$thread->category] }} · {{ $thread->location?->name ?? 'Manitoba-wide' }}</p>
                        <h2><a href="/discussions/{{ $thread->id }}">{{ $thread->title }}</a></h2>
                        <p>
                            @if($thread->author)
                                <a class="discussion-member" href="/users/{{ $thread->author->id }}">{{ $thread->author->name }}</a>
                            @else
                                Former member
                            @endif
                            · {{ $thread->replies_count }} {{ \Illuminate\Support\Str::plural('reply', $thread->replies_count) }}
                            @if($thread->last_activity_at)
                                · Active <time datetime="{{ $thread->last_activity_at->toIso8601String() }}">{{ $thread->last_activity_at->diffForHumans() }}</time>
                            @endif
                            @if($thread->pinned) · Pinned @endif
                            @if($thread->locked) · Locked @endif
                        </p>
                    </div>
                </article>
            @empty
                @if($filtered)
                    <p>No discussions match these filters.</p>
                @else
                    <p>No discussions have been published in {{ $area }} yet.</p>
                @endif
            @endforelse
        </div>
        {{ $threads->links() }}
    </div>
</x-layouts.site>
