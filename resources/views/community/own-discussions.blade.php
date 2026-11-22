<x-layouts.site title="My Discussions">
    <div class="container page-shell narrow">
        <div class="page-intro">
            <h1>My Discussions</h1>
            <div class="inline-actions">
                <a class="button button-outline" href="/discussions">All discussions</a>
                <a class="button button-primary" href="/discussions/new">Start a discussion</a>
            </div>
        </div>
        <section class="panel" aria-label="Your discussions">
            @forelse($items as $item)
                @php($posted = $item->created_at?->timezone('America/Winnipeg'))
                <article class="item-row">
                    <div>
                        <h2><a class="text-link" href="/account/discussions/{{ $item->id }}/preview">{{ $item->title }}</a></h2>
                        <p>{{ $item->status === 'pending' ? 'Pending review' : ucfirst($item->status) }}
                            @if($item->locked) · Locked @endif
                            · {{ $item->location?->name ?? 'Manitoba-wide' }}
                            · {{ $item->replies_count }} {{ \Illuminate\Support\Str::plural('reply', $item->replies_count) }}
                        </p>
                        @if($posted)<p class="field-note">Posted <time datetime="{{ $posted->toDateString() }}">{{ $posted->format('M j, Y') }}</time></p>@endif
                        @if(filled($reasons[$item->id] ?? null))<p class="prose-text">Moderation reason · {{ $reasons[$item->id] }}</p>@endif
                    </div>
                </article>
            @empty
                <p>You haven’t started any discussions here yet.</p>
            @endforelse
            {{ $items->links() }}
        </section>
    </div>
</x-layouts.site>
