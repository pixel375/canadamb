<x-layouts.admin title="News editorial">
    <div class="page-intro"><div><h1>News editorial</h1><p>Review source rights, dates and claims before publishing.</p></div></div>
    <section class="panel">
        <h2>Sources</h2>
        @foreach($sources as $source)
            <div class="item-row"><div><h3>{{ $source->name }}</h3><p>{{ $source->url }} · {{ $source->reuse }}</p>
                @if($source->feed_url)<p>Feed configured · {{ $source->poll_failures }} consecutive poll failures</p>@endif
            </div>
                @can('manage-network')<form method="post" action="/admin/news/source/{{ $source->id }}">@csrf
                    <button class="button button-outline" name="enabled" value="{{ $source->enabled ? 0 : 1 }}">{{ $source->enabled ? 'Disable' : 'Enable' }}</button>
                </form>
                <details><summary>Configure feed</summary>
                    <form class="form-stack mt-2" method="post" action="/admin/news/source/{{ $source->id }}/feed">@csrf
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

    @if($feedCandidates->isNotEmpty() || $feedChecks->isNotEmpty())
        <section class="panel">
            <h2>Feed candidates</h2>
            @forelse($feedCandidates as $item)
                <div class="item-row"><div><h3>{{ $item->title }}</h3><p>{{ $item->source_name }}@if($item->source_date) · {{ $item->source_date }}@endif</p></div>
                    <a class="button button-outline" href="/admin/news?candidate={{ $item->id }}">Review candidate</a>
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

    <section class="panel">
        <h2>Editorial queue</h2>
        @forelse($articles as $article)
            <article class="form-stack border-b border-gray-300 py-4">
                <div><h3>{{ $article->title }}</h3><p>{{ ucfirst($article->status) }} · {{ $article->location?->name ?? 'Manitoba' }} · {{ $article->source_date->format('M j, Y') }}{{ $article->sensitive ? ' · Sensitive coverage' : '' }}</p></div>
                <a class="text-link" href="{{ $article->source_url }}" rel="noreferrer">{{ $article->source->name }} <x-icon name="arrow" size="16"/></a>
                <form class="form-stack" method="post" action="/admin/news/{{ $article->id }}/publish">@csrf
                    <label>Headline<input name="title" value="{{ $article->title }}" maxlength="140" required></label>
                    <label>Summary<textarea name="body" maxlength="2500" rows="4" required>{{ $article->body }}</textarea></label>
                    <label>Fact-check and correction notes<textarea name="verification" rows="2" minlength="20" maxlength="3000" required></textarea></label>
                    <label>Corroborating link<input name="corroboration_url" type="url" value="{{ $article->corroboration_url }}" maxlength="1024" @required($article->sensitive)></label>
                    <div><button class="button button-primary">{{ $article->status === 'draft' ? 'Publish article' : 'Publish update' }}</button></div>
                </form>
                <details><summary>Withdraw</summary>
                    <form class="form-stack mt-2" method="post" action="/admin/news/{{ $article->id }}/withdraw">@csrf
                        <label>Reason<textarea name="verification" rows="2" minlength="10" maxlength="3000" required></textarea></label>
                        <div><button class="button button-outline">Withdraw article</button></div>
                    </form>
                </details>
            </article>
        @empty<p>No news articles are awaiting review or published.</p>@endforelse
        {{ $articles->links() }}
    </section>
</x-layouts.admin>
