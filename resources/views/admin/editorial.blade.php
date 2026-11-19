<x-layouts.admin title="News editorial">
    <div class="page-intro"><div><h1>News editorial</h1><p>Review source rights, dates and claims before publishing.</p></div></div>
    <section class="panel">
        <h2>Sources</h2>
        @foreach($sources as $source)
            <div class="item-row"><div><h3>{{ $source->name }}</h3><p>{{ $source->url }} · {{ $source->reuse }}</p></div>
                @can('manage-network')<form method="post" action="/admin/news/source/{{ $source->id }}">@csrf
                    <button class="button button-outline" name="enabled" value="{{ $source->enabled ? 0 : 1 }}">{{ $source->enabled ? 'Disable' : 'Enable' }}</button>
                </form>@endcan
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
        <details class="panel"><summary>Write a draft</summary>
            <form class="form-stack mt-3" method="post" action="/admin/news/draft">@csrf
                <label>Source<select name="source_id" required>@foreach($sources as $source)@if($source->enabled && $source->reuse === 'summary-permitted')<option value="{{ $source->id }}">{{ $source->name }}</option>@endif @endforeach</select></label>
                <div class="form-grid"><label>Source record ID<input name="external_key" maxlength="100" required></label><label>Source date<input name="source_date" type="date" required></label></div>
                <label>Original source link<input name="source_url" type="url" maxlength="1024" required></label>
                <label>Location<select name="location_id"><option value="">Use source coverage</option>@foreach($places as $place)<option value="{{ $place->id }}">{{ $place->name }}</option>@endforeach</select></label>
                <label>Category<select name="category" required>@foreach(\App\Support\News::CATEGORIES as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach</select></label>
                <label>Headline<input name="title" maxlength="140" required></label>
                <label>Original summary<textarea name="body" maxlength="2500" rows="5" required></textarea></label>
                <label class="checkbox"><input type="checkbox" name="sensitive" value="1"> Sensitive coverage</label>
                <div><button class="button button-primary">Save editorial draft</button></div>
            </form>
        </details>
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
