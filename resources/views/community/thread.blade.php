@php($currentSite = request()->attributes->get('site'))
@php($canParticipate = auth()->check() && auth()->user()->hasVerifiedEmail())
<x-layouts.site :title="$item->title.' | Discussions'" :description="\Illuminate\Support\Str::limit($item->body, 155)" :canonical="$canonical" :location="$item->location">
    <div class="container page-shell discussion-thread-shell">
        <nav class="discussion-breadcrumb" aria-label="Discussion navigation">
            <a class="text-link" href="{{ \App\Support\LocationScope::browseUrl($currentSite, '/community', $item->location) }}">Discussions</a>
            <x-icon name="chevron" size="14"/>
            <a class="text-link" href="/community?{{ http_build_query(array_filter(['category' => $item->category, 'location' => $currentSite->isNetwork() ? $item->location?->slug : null])) }}">{{ \App\Support\Community::FORUMS[$item->category] }}</a>
        </nav>

        <div class="discussion-thread-layout">
            <article class="panel discussion-original" aria-labelledby="discussion-title">
                <div class="discussion-thread-labels">
                    <span class="discussion-thread-category">{{ \App\Support\Community::FORUMS[$item->category] }}</span>
                    <span class="discussion-thread-location"><x-icon name="pin" size="14"/> {{ $item->location?->name ?? 'Manitoba' }}</span>
                    @if($item->pinned)
                        <span class="discussion-thread-status">Pinned</span>
                    @endif
                    @if($item->locked)
                        <span class="discussion-thread-status">Locked</span>
                    @endif
                </div>
                <h1 id="discussion-title">{{ $item->title }}</h1>
                <div class="discussion-author-line">
                    <span class="discussion-avatar" aria-hidden="true">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($item->author?->name ?? 'M', 0, 1)) }}</span>
                    <div class="discussion-author-copy">
                        <strong>
                            @if($item->author)
                                <a href="/users/{{ $item->author->id }}">{{ $item->author->name }}</a>
                            @else
                                Former member
                            @endif
                        </strong>
                        <time datetime="{{ $item->created_at->toIso8601String() }}">{{ $item->created_at->timezone('America/Winnipeg')->format('M j, Y · g:i a T') }}</time>
                    </div>
                </div>
                <div class="discussion-original-copy prose-text">{{ $item->body }}</div>
                <div class="discussion-original-footer">
                    <a class="text-link" href="#discussion-replies"><x-icon name="messages" size="16"/> {{ $item->replies_count }} {{ \Illuminate\Support\Str::plural('reply', $item->replies_count) }}</a>
                    @if(! $item->locked && $canParticipate)
                        <a class="button button-outline" href="#reply-composer"><x-icon name="arrow" size="16"/> Write a reply</a>
                    @endif
                </div>
            </article>

            <aside class="discussion-thread-aside" aria-label="Discussion actions and details">
                <section class="panel discussion-context">
                    <p class="section-label">Community conversation</p>
                    <h2>Join the discussion</h2>
                    <p>Share useful information, ask a question, or follow updates from your neighbours.</p>
                    <dl class="discussion-facts">
                        <div><dt>Location</dt><dd>{{ $item->location?->name ?? 'Manitoba' }}</dd></div>
                        <div><dt>Category</dt><dd>{{ \App\Support\Community::FORUMS[$item->category] }}</dd></div>
                        <div><dt>Replies</dt><dd>{{ $item->replies_count }}</dd></div>
                    </dl>
                    @if($item->locked)
                        <p class="discussion-context-notice"><x-icon name="shield" size="18"/> This discussion is closed to new replies.</p>
                    @elseif($canParticipate)
                        <a class="button button-primary discussion-context-action" href="#reply-composer"><x-icon name="messages" size="18"/> Add a reply</a>
                    @elseif(auth()->check())
                        <p class="discussion-context-notice">Verify your email before posting replies.</p>
                        <a class="button button-primary discussion-context-action" href="/account/settings">Account settings</a>
                    @else
                        <a class="button button-primary discussion-context-action" href="{{ $currentSite->isNetwork() ? '/login' : '/auth/start' }}">Sign in to join</a>
                    @endif
                    @if($canParticipate)
                        <form class="discussion-follow-form" method="post" action="/community/{{ $item->id }}/follow">
                            @csrf
                            @if($following)
                                @method('DELETE')
                            @endif
                            <button type="submit" class="button button-outline discussion-context-action">
                                <x-icon name="bell" size="17"/> {{ $following ? 'Stop following' : 'Follow discussion' }}
                            </button>
                        </form>
                        <p class="discussion-follow-hint">{{ $following ? 'You will no longer receive new reply notifications after unfollowing.' : 'Get notifications when approved replies are posted.' }}</p>
                        <a class="text-link discussion-followed-link" href="/account/followed-discussions">My followed discussions <x-icon name="arrow" size="15"/></a>
                    @endif
                </section>
                @if($related->isNotEmpty())
                    <section class="panel discussion-related"><h2>Related discussions</h2>@foreach($related as $topic)<div><a class="text-link" href="/community/{{ $topic->id }}">{{ $topic->title }}</a><p class="field-note">{{ $topic->replies_count }} {{ \Illuminate\Support\Str::plural('reply', $topic->replies_count) }}</p></div>@endforeach</section>
                @endif
                <a class="discussion-thread-all" href="{{ \App\Support\LocationScope::browseUrl($currentSite, '/community', $item->location) }}"><x-icon name="arrow" size="16"/> Browse more discussions</a>
            </aside>

            <section id="discussion-replies" class="panel discussion-replies" aria-labelledby="discussion-replies-heading">
                <header class="discussion-replies-heading">
                    <div>
                        <p class="section-label">The conversation</p>
                        <h2 id="discussion-replies-heading">Replies</h2>
                    </div>
                    <span class="discussion-count">{{ $item->replies_count }} {{ \Illuminate\Support\Str::plural('reply', $item->replies_count) }}</span>
                </header>
                @forelse($replies as $reply)
                    <article class="discussion-reply" id="reply-{{ $reply->id }}">
                        <span class="discussion-avatar discussion-reply-avatar" aria-hidden="true">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($reply->author?->name ?? 'M', 0, 1)) }}</span>
                        <div class="discussion-reply-content">
                            <div class="discussion-reply-byline">
                                <strong>
                                    @if($reply->author)
                                        <a href="/users/{{ $reply->author->id }}">{{ $reply->author->name }}</a>
                                    @else
                                        Former member
                                    @endif
                                </strong>
                                <time datetime="{{ $reply->created_at->toIso8601String() }}">{{ $reply->created_at->timezone('America/Winnipeg')->format('M j, Y · g:i a T') }}</time>
                            </div>
                            <p class="prose-text">{{ $reply->body }}</p>
                            <a class="text-link reply-permalink" href="/community/{{ $item->id }}/replies/{{ $reply->id }}">Link to this reply</a>
                        </div>
                    </article>
                @empty
                    <div class="discussion-no-replies">
                        <x-icon name="messages" size="25"/>
                        <strong>No published replies yet</strong>
                        <p>Be the first to contribute to this conversation.</p>
                    </div>
                @endforelse
                {{ $replies->links() }}
            </section>

            @if($item->locked)
                <div class="panel discussion-reply-notice" role="status">
                    <x-icon name="shield" size="22"/>
                    <div><h2>Discussion closed</h2><p>This discussion is locked and is no longer accepting replies.</p></div>
                </div>
            @elseif($canParticipate)
                <form id="reply-composer" method="post" action="/community/{{ $item->id }}/replies" class="panel form-stack discussion-reply-composer">
                    @csrf
                    <div>
                        <p class="section-label">Your voice matters</p>
                        <h2>Write a reply</h2>
                        <p>Keep your reply helpful and respectful. Replies appear after moderation.</p>
                    </div>
                    <label for="discussion-reply-body">Your reply
                        <textarea id="discussion-reply-body" data-character-count name="body" required minlength="5" maxlength="3000" rows="5" placeholder="Share your thoughts with the community...">{{ old('body') }}</textarea><small class="field-note" data-character-feedback></small>
                    </label>
                    <div class="discussion-reply-submit">
                        <span>5–3,000 characters · Reviewed before publication</span>
                        <button class="button button-primary" type="submit"><x-icon name="arrow" size="17"/> Submit reply for review</button>
                    </div>
                </form>
            @elseif(auth()->check())
                <div class="panel discussion-reply-notice" role="status">
                    <x-icon name="shield" size="22"/>
                    <div><h2>Verify your email to reply</h2><p>Visit your account settings to complete verification before joining the discussion.</p><a class="text-link" href="/account/settings">Account settings</a></div>
                </div>
            @else
                <div class="panel discussion-reply-notice">
                    <x-icon name="messages" size="22"/>
                    <div><h2>Have something to add?</h2><p>Sign in to reply to this discussion or follow new replies.</p><a class="button button-primary" href="{{ $currentSite->isNetwork() ? '/login' : '/auth/start' }}">Sign in to participate</a></div>
                </div>
            @endif
        </div>
    </div>
    <div class="container mb-4"><a class="text-link" href="/contact?topic=community">Raise a concern about this discussion</a> · <a class="text-link" href="/safety">Community guidance</a></div>
</x-layouts.site>
