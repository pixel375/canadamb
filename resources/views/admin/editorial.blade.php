<x-layouts.admin title="News editorial">
    <div class="page-intro"><div><h1>News editorial</h1><p>Review source rights, dates and claims before publishing.</p></div></div>
    <nav class="inline-actions admin-jump-links" aria-label="Editorial sections"><a class="button button-outline" href="#editorial-queue">Editorial queue ({{ $articles->total() }})</a><a class="button button-outline" href="#feed-candidates">Feed candidates ({{ $feedCandidates->total() }})</a><a class="button button-outline" href="#news-sources">Sources</a></nav>
    <section class="panel" id="news-sources">
        <h2>Sources</h2>
        @foreach($sources as $source)
            <div class="item-row"><div><h3>{{ $source->name }}</h3><p>{{ $source->url }} · {{ $source->reuse }}</p>
                @if($source->feed_url)<p>Feed configured · {{ $source->poll_failures }} consecutive poll failures</p>@endif
            </div>
                @can('manage-network')<form method="post" action="/admin/news/source/{{ $source->id }}">@csrf<input type="hidden" name="version" value="{{ $source->configurationVersion() }}">
                    <button class="button button-outline" name="enabled" value="{{ $source->enabled ? 0 : 1 }}">{{ $source->enabled ? 'Disable' : 'Enable' }}</button>
                </form>
                <details><summary>Review reuse rights</summary>
                    <form class="form-stack mt-2" method="post" action="/admin/news/source/{{ $source->id }}/rights">@csrf<input type="hidden" name="version" value="{{ $source->configurationVersion() }}">
                        <label>Reuse rights<select name="reuse">@foreach(['unreviewed','link-only','summary-permitted'] as $right)<option value="{{ $right }}" @selected($source->reuse === $right)>{{ ucfirst(str_replace('-', ' ', $right)) }}</option>@endforeach</select></label>
                        <label>Permission notes<textarea name="reuse_notes" maxlength="2000" required>{{ $source->reuse_notes }}</textarea></label>
                        <p class="field-note">Changes affect new intake and publication. Review already published articles separately.</p><button class="button button-outline">Save rights</button>
                    </form>
                </details>
                <details><summary>Configure feed</summary>
                    <form class="form-stack mt-2" method="post" action="/admin/news/source/{{ $source->id }}/feed">@csrf<input type="hidden" name="version" value="{{ $source->configurationVersion() }}">
                        <label>RSS or Atom URL<input name="feed_url" type="url" maxlength="1024" value="{{ $source->feed_url }}"></label>
                        <p class="field-note">Leave blank to remove the feed.</p>
                        <button class="button button-outline">Save feed</button>
                    </form>
                </details>@endcan
            </div>
        @endforeach
        @can('manage-network')<details class="mt-3"><summary>Add source</summary>
            <form class="form-stack mt-2" method="post" action="/admin/news/source">@csrf
                <div class="form-grid"><label>Name<input name="name" required maxlength="150"></label><label>HTTPS URL<input name="url" type="url" required maxlength="255"></label></div>
                <label>Location coverage<select name="coverage_location_id"><option value="">Manitoba-wide</option>@foreach($places as $place)<option value="{{ $place->id }}">{{ $place->name }}</option>@endforeach</select></label>
                <label>Reuse rights<select name="reuse"><option value="unreviewed">Unreviewed</option><option value="link-only">Link only</option><option value="summary-permitted">Summaries permitted</option></select></label>
                <label>Permission or license notes<textarea name="reuse_notes" required maxlength="2000" rows="3"></textarea></label>
                <label class="checkbox"><input type="checkbox" name="enabled" value="1"> Enable submissions</label>
                <div><button class="button button-primary">Save source</button></div>
            </form>
        </details>@endcan
    </section>

    @if($sources->contains(fn ($source) => $source->enabled && $source->reuse === 'summary-permitted'))
        <details class="panel" @if($candidate) open @endif><summary>Write a draft</summary>
            <form class="form-stack mt-3" method="post" action="/admin/news/draft">@csrf
                @if($candidate)
                <input type="hidden" name="candidate_id" value="{{ $candidate->id }}">
                <div><p>{{ $candidate->source_name }}</p><a class="text-link" href="{{ $candidate->source_url }}" rel="noreferrer">{{ $candidate->title }}</a></div>
                <label>Source date<input name="source_date" type="date" required value="{{ old('source_date', $candidate->source_date) }}"></label>
                @else
                <label>Source<select name="source_id" required>@foreach($sources as $source)@if($source->enabled && $source->reuse === 'summary-permitted')<option value="{{ $source->id }}">{{ $source->name }}</option>@endif @endforeach</select></label>
                <div class="form-grid"><label>Source record ID<input name="external_key" maxlength="100" required></label><label>Source date<input name="source_date" type="date" required></label></div>
                <label>Original source link<input name="source_url" type="url" maxlength="1024" required></label>
                @endif
                <label>Location<select name="location_id"><option value="">Use source coverage</option>@foreach($places as $place)<option value="{{ $place->id }}">{{ $place->name }}</option>@endforeach</select></label>
                <label>Category<select name="category" required>@foreach(\App\Support\News::CATEGORIES as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach</select></label>
                <label>Headline<input name="title" maxlength="140" required></label>
                <label>Original summary<textarea name="body" maxlength="2500" rows="5" required></textarea></label>
                <label class="checkbox"><input type="checkbox" name="sensitive" value="1"> Sensitive coverage</label>
                <div><button class="button button-primary">Save editorial draft</button></div>
            </form>
        </details>
    @endif

    @if($candidateReviewEnabled || $feedCandidates->isNotEmpty() || $feedChecks->isNotEmpty() || filled($filters['feed_q'] ?? null) || filled($filters['feed_source'] ?? null))
        <section class="panel" id="feed-candidates">
            <h2>Feed candidates</h2>
            <form method="get" action="/admin/news#feed-candidates" class="form-stack mb-4">
                @foreach(['q','status','source_id','location'] as $field)
                    @if(filled($filters[$field] ?? null))<input type="hidden" name="{{ $field }}" value="{{ $filters[$field] }}">@endif
                @endforeach
                <div class="form-grid"><label>Search candidates<input type="search" name="feed_q" maxlength="100" value="{{ $filters['feed_q'] ?? '' }}"></label><label>Source<select name="feed_source"><option value="">All sources</option>@foreach($sources as $source)<option value="{{ $source->id }}" @selected((int) ($filters['feed_source'] ?? 0) === $source->id)>{{ $source->name }}</option>@endforeach</select></label>
                @if($candidateReviewEnabled)
                    <label>Review state<select name="feed_view"><option value="pending" @selected(($filters['feed_view'] ?? 'pending') === 'pending')>Pending</option><option value="dismissed" @selected(($filters['feed_view'] ?? '') === 'dismissed')>Dismissed</option></select></label>
                @endif
                </div><div class="inline-actions"><button class="button button-primary">Filter candidates</button><a class="button button-outline" href="/admin/news#feed-candidates">Reset</a></div>
            </form>
            @forelse($feedCandidates as $item)
                <div class="item-row"><div><h3>{{ $item->title }}</h3><p>{{ $item->source_name }}@if($item->source_date) · {{ $item->source_date }}@endif</p></div>
                    @if(($filters['feed_view'] ?? 'pending') !== 'dismissed')<a class="button button-outline" href="/admin/news?candidate={{ $item->id }}">Review candidate</a>@endif
                    @if($candidateReviewEnabled)<details><summary>{{ ($filters['feed_view'] ?? '') === 'dismissed' ? 'Restore' : 'Dismiss' }}</summary>
                        <form method="post" action="/admin/news/candidate/{{ $item->id }}/review" class="form-stack">@csrf
                            <label>Review reason<input name="reason" minlength="8" maxlength="300" required></label>
                            <button class="button button-outline" name="decision" value="{{ ($filters['feed_view'] ?? '') === 'dismissed' ? 'restore' : 'dismiss' }}">Save decision</button>
                        </form></details>@endif
                </div>
            @empty<p>No feed candidates are waiting for review.</p>@endforelse
            {{ $feedCandidates->links() }}
            @if($feedChecks->isNotEmpty())
                <details class="mt-3"><summary>Recent feed health checks</summary>
                    @foreach($feedChecks as $check)
                        <div class="item-row"><div><h3>{{ $check->name }}</h3><p>{{ $check->detail ?? ($check->new_candidates.' new candidates') }}</p></div>
                            <span>{{ ucfirst($check->status) }} · {{ $check->created_at }}</span>
                        </div>
                    @endforeach
                </details>
            @endif
        </section>
    @endif

    <section class="panel" id="editorial-queue">
        <h2>Editorial queue</h2>
        <form method="get" action="/admin/news#editorial-queue" class="form-stack mb-4">
            @foreach(['feed_q','feed_source','feed_view'] as $field)
                @if(filled($filters[$field] ?? null))<input type="hidden" name="{{ $field }}" value="{{ $filters[$field] }}">@endif
            @endforeach
            <label>Search articles<input name="q" type="search" value="{{ $filters['q'] ?? '' }}" maxlength="100"></label>
            <div class="form-grid"><label>Status<select name="status"><option value="">Active queue</option>@foreach(['draft','published','withdrawn'] as $state)<option value="{{ $state }}" @selected(($filters['status'] ?? '') === $state)>{{ ucfirst($state) }}</option>@endforeach</select></label>
            <label>Source<select name="source_id"><option value="">All sources</option>@foreach($sources as $source)<option value="{{ $source->id }}" @selected((int) ($filters['source_id'] ?? 0) === $source->id)>{{ $source->name }}</option>@endforeach</select></label>
            <label>Community<select name="location"><option value="">All locations</option>@foreach($places as $place)<option value="{{ $place->id }}" @selected((int) ($filters['location'] ?? 0) === $place->id)>{{ $place->name }}</option>@endforeach</select></label></div>
            <div class="inline-actions"><button class="button button-primary">Filter queue</button><a class="button button-outline" href="/admin/news#editorial-queue">Reset</a></div>
        </form>
        @forelse($articles as $article)
            <article class="admin-review-card form-stack">
                <div><h3>{{ $article->title }}</h3><p>{{ ucfirst($article->status) }} · {{ $article->location?->name ?? 'Manitoba' }} · {{ $article->source_date->format('M j, Y') }}{{ $article->sensitive ? ' · Sensitive coverage' : '' }}</p></div>
                <a class="text-link" href="{{ $article->source_url }}" rel="noreferrer">{{ $article->source->name }} <x-icon name="arrow" size="16"/></a>
                <div class="inline-actions"><a class="button button-outline" href="/admin/news/{{ $article->id }}/history">Revision history</a>@if($article->status === 'draft')<a class="button button-outline" href="/admin/news/{{ $article->id }}/edit">Edit without publishing</a>@endif</div>
                @if($article->status !== 'withdrawn')
                <form class="form-stack" method="post" action="/admin/news/{{ $article->id }}/publish">@csrf
                    <input type="hidden" name="version" value="{{ $article->reviewVersion() }}">
                    <label>Headline<input name="title" value="{{ $article->title }}" maxlength="140" required></label>
                    <label>Summary<textarea name="body" maxlength="2500" rows="4" required>{{ $article->body }}</textarea></label>
                    <label>Fact-check and correction notes<textarea name="verification" rows="2" minlength="20" maxlength="3000" required></textarea></label>
                    <label>Corroborating link<input name="corroboration_url" type="url" value="{{ $article->corroboration_url }}" maxlength="1024" @required($article->sensitive)></label>
                    <div><button class="button button-primary">{{ $article->status === 'draft' ? 'Publish article' : 'Publish update' }}</button></div>
                </form>
                <details><summary>Withdraw</summary>
                    <form class="form-stack mt-2" method="post" action="/admin/news/{{ $article->id }}/withdraw">@csrf
                        <input type="hidden" name="version" value="{{ $article->reviewVersion() }}">
                        <label>Reason<textarea name="verification" rows="2" minlength="10" maxlength="3000" required></textarea></label>
                        <div><button class="button button-outline">Withdraw article</button></div>
                    </form>
                </details>
                @else
                    <p class="field-note">Withdrawn articles are retained in the editorial history.</p>
                @endif
            </article>
        @empty<p>No news articles are awaiting review or published.</p>@endforelse
        {{ $articles->links() }}
    </section>
</x-layouts.admin>
