<x-layouts.site title="Manage discussion">
    <div class="container page-shell narrow">
        <div class="page-intro">
            <div>
                <a class="text-link" href="/account/discussions">My Discussions</a>
                <h1>Manage discussion</h1>
            </div>
            @if($item->status === 'approved')
                <a class="button button-outline" href="/discussions/{{ $item->id }}">View public discussion</a>
            @endif
        </div>
        <article class="panel form-stack">
            <div>
                <p class="field-note">{{ $item->status === 'pending' ? 'Pending review' : ucfirst($item->status) }}
                    @if($item->locked) · Locked @endif
                    · {{ $item->location?->name ?? 'Manitoba-wide' }}
                    · {{ \App\Support\Community::FORUMS[$item->category] ?? 'Discussion' }}
                </p>
                <h2 class="prose-text">{{ $item->title }}</h2>
                <p class="prose-text">{{ $item->body }}</p>
                @if(filled($reason))<p class="prose-text">Moderation reason · {{ $reason }}</p>@endif
            </div>
            @if($item->locked)
                <p>A moderator locked this discussion. You can still read your post here.</p>
            @elseif($item->status === 'rejected')
                <p>You can revise your post and send it for another review.</p>
            @elseif($item->status === 'withdrawn')
                <p>This discussion is hidden. You can restore it for review before it can appear again.</p>
            @endif
            @if($canEdit || $canWithdraw || $canRestore)
                <div class="inline-actions">
                    @if($canEdit)<a class="button button-primary" href="/account/discussions/{{ $item->id }}/edit">Edit discussion</a>@endif
                    @if($canWithdraw)
                        <form method="post" action="/account/discussions/{{ $item->id }}/withdraw">
                            @csrf
                            <input type="hidden" name="version" value="{{ $version }}">
                            <button class="button button-outline" type="submit">Withdraw discussion</button>
                        </form>
                    @endif
                    @if($canRestore)
                        <form method="post" action="/account/discussions/{{ $item->id }}/restore">
                            @csrf
                            <input type="hidden" name="version" value="{{ $version }}">
                            <button class="button button-primary" type="submit">Restore for review</button>
                        </form>
                    @endif
                </div>
            @endif
            @if($event)
                <p><a class="text-link" href="/account/events">Manage event request</a> · {{ $event->status === 'pending' ? 'Pending review' : ucfirst($event->status) }}</p>
            @elseif($canRequestEvent)
                <p><a class="text-link" href="/discussions/{{ $item->id }}/event">Request event listing</a></p>
            @elseif($item->status === 'pending')
                <p class="field-note">An event listing can be requested once this discussion is approved.</p>
            @endif
        </article>
    </div>
</x-layouts.site>
