<x-layouts.admin title="Community moderation">
    <div class="page-intro"><div><h1>Community moderation</h1><p>Review discussions, replies, and event submissions.</p></div></div>
    <section class="panel"><h2>Discussions</h2>
        @forelse($threads as $item)
            <article class="form-stack border-b border-gray-300 py-4">
                <div><h3>{{ $item->title }}</h3><p>{{ $item->author?->name ?? 'Former member' }} · {{ $item->location?->name ?? 'Manitoba' }} · {{ ucfirst($item->status) }}</p></div>
                <p class="prose-text">{{ $item->body }}</p>
                <x-community-moderation-form :path="'/admin/community/threads/'.$item->id" :version="\App\Support\ModerationDecision::version('thread', $item)" :approved="$item->status === 'approved'" :thread-controls="true" :pinned="$item->pinned" :locked="$item->locked"/>
            </article>
        @empty<p>No discussions to moderate.</p>@endforelse
        {{ $threads->links() }}
    </section>
    <section class="panel"><h2>Replies</h2>
        @forelse($replies as $item)
            <article class="form-stack border-b border-gray-300 py-4"><h3>{{ $item->thread->title }}</h3><p>{{ $item->author?->name ?? 'Former member' }}</p><p class="prose-text">{{ $item->body }}</p>
                <p>{{ ucfirst($item->status) }}</p><x-community-moderation-form :path="'/admin/community/replies/'.$item->id" :version="\App\Support\ModerationDecision::version('reply', $item)" :approved="$item->status === 'approved'"/>
            </article>
        @empty<p>No replies awaiting review.</p>@endforelse
        {{ $replies->links() }}
    </section>
    <section class="panel"><h2>Events</h2>
        @forelse($events as $item)
            <article class="form-stack border-b border-gray-300 py-4"><h3>{{ $item->title }}</h3><p>{{ $item->location->name }} · {{ $item->venue }} · {{ $item->organizer }} · {{ $item->localStart()->format('M j, Y · g:i a T') }}</p>
                <p class="prose-text">{{ $item->body }}</p>
                @if($item->localEnd())<p>Ends {{ $item->localEnd()->format('M j, Y · g:i a T') }}</p>@endif
                @if($item->event_url)<a class="text-link" rel="noreferrer" href="{{ $item->event_url }}">{{ $item->event_url }}</a>@endif
                <p>{{ ucfirst($item->status) }}</p>
                @if($item->cancellation_note)<p>Private cancellation note · {{ $item->cancellation_note }}</p>@endif
                <x-community-moderation-form :path="'/admin/community/events/'.$item->id" :version="\App\Support\ModerationDecision::version('event', $item)" :approved="$item->status !== 'pending'"/>
            </article>
        @empty<p>No events awaiting review.</p>@endforelse
        {{ $events->links() }}
    </section>
</x-layouts.admin>
