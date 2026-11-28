<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\NewsArticle;
use App\Models\NewsSource;
use App\Support\Audit;
use App\Support\KeywordSearch;
use App\Support\News;
use App\Support\NewsFeeds\FeedUrl;
use App\Support\NewsReview;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EditorialController extends Controller
{
    public function index(Request $request)
    {
        $input = $request->validate([
            'candidate' => 'nullable|integer|min:1', 'status' => 'nullable|in:draft,published,withdrawn',
            'q' => 'nullable|string|max:100', 'source_id' => 'nullable|integer|exists:news_sources,id',
            'feed_view' => 'nullable|in:pending,dismissed', 'feed_q' => 'nullable|string|max:100',
            'feed_source' => 'nullable|integer|exists:news_sources,id',
            'location' => 'nullable|integer|exists:locations,id',
        ]);

        return view('admin.editorial', [
            'articles' => NewsArticle::with(['source', 'location'])
                ->when($input['status'] ?? null, fn ($query, $status) => $query->where('status', $status), fn ($query) => $query->whereIn('status', ['draft', 'published']))
                ->when($input['source_id'] ?? null, fn ($query, $source) => $query->where('source_id', $source))
                ->when(filled($input['q'] ?? null), fn ($query) => KeywordSearch::apply($query, $input['q'], ['title', 'body']))
                ->when($input['location'] ?? null, fn ($query, $id) => $query->where('location_id', $id))
                ->orderByRaw("CASE WHEN status = 'draft' THEN 0 ELSE 1 END")->latest()->latest('id')->paginate(12)->withQueryString(),
            'filters' => $input, 'candidateReviewEnabled' => NewsReview::enabled(),
            'sources' => NewsSource::orderBy('name')->get(),
            'places' => Location::selectable()->orderBy('name')->get(),
            'feedCandidates' => $this->feedCandidates(dismissed: ($input['feed_view'] ?? null) === 'dismissed')
                ->when($input['feed_source'] ?? null, fn ($query, $id) => $query->where('news_feed_candidates.source_id', $id))
                ->when(filled($input['feed_q'] ?? null), function ($query) use ($input) {
                    foreach (KeywordSearch::patterns($input['feed_q']) as $pattern) {
                        $query->whereRaw("LOWER(news_feed_candidates.title) LIKE ? ESCAPE '!'", [$pattern]);
                    }
                })->orderByDesc('news_feed_candidates.id')->paginate(12, pageName: 'feeds')->withQueryString(),
            'candidate' => isset($input['candidate'])
                ? $this->feedCandidates()->where('news_feed_candidates.id', $input['candidate'])->firstOrFail()
                : null,
            'feedChecks' => DB::table('news_feed_checks')->join('news_sources', 'news_sources.id', '=', 'news_feed_checks.source_id')
                ->latest('news_feed_checks.id')->limit(20)->get(['news_feed_checks.*', 'news_sources.name']),
        ]);
    }

    public function feedHealth(Request $request)
    {
        $filters = $request->validate([
            'source_id' => 'nullable|integer|exists:news_sources,id',
            'status' => 'nullable|in:success,failed', 'period' => 'nullable|in:1,7,30,90',
        ]);
        $sources = NewsSource::orderBy('name')->get(['id', 'name', 'enabled', 'reuse', 'feed_url', 'next_poll_at', 'poll_failures']);
        $period = (int) ($filters['period'] ?? 7);
        $query = DB::table('news_feed_checks')->join('news_sources', 'news_sources.id', '=', 'news_feed_checks.source_id')
            ->where('news_feed_checks.created_at', '>=', now()->subDays($period))
            ->when($filters['source_id'] ?? null, fn ($query, $id) => $query->where('news_feed_checks.source_id', $id));
        $counts = (clone $query)->selectRaw('news_feed_checks.status, COUNT(*) AS total')->groupBy('news_feed_checks.status')->pluck('total', 'status');
        $checks = $query->when($filters['status'] ?? null, fn ($query, $status) => $query->where('news_feed_checks.status', $status))
            ->latest('news_feed_checks.id')->paginate(25, ['news_feed_checks.id', 'news_feed_checks.status', 'news_feed_checks.new_candidates', 'news_feed_checks.created_at', 'news_sources.name'])->appends($filters);
        $polling = (bool) config('network.news_polling') && ! config('network.prelaunch');

        return response()->view('admin.feed-health', compact('sources', 'checks', 'counts', 'filters', 'period', 'polling'))->header('Cache-Control', 'private, no-store');
    }

    public function source(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:150|unique:news_sources,name',
            'url' => 'required|url:https|max:255',
            'coverage_location_id' => ['nullable', Rule::exists('locations', 'id')->where('province', 'MB')->where('selectable', true)],
            'reuse' => 'required|in:summary-permitted,link-only,unreviewed',
            'reuse_notes' => 'required|string|max:2000',
            'enabled' => 'nullable|boolean',
        ]);
        DB::transaction(function () use ($request, $data) {
            $source = NewsSource::create($data);
            Audit::record('news.source-created', $request->user()->id, context: ['source' => $source->id]);
        });

        return redirect('/admin/news')->with('status', 'News source added.');
    }

    public function sourceStatus(Request $request, NewsSource $source)
    {
        $data = $request->validate(['enabled' => 'required|boolean', 'version' => 'required|string|size:64']);
        DB::transaction(function () use ($request, $source, $data) {
            $source = NewsSource::lockForUpdate()->findOrFail($source->id);
            $this->checkSourceVersion($source, $data['version']);
            if ($source->enabled === (bool) $data['enabled']) {
                return;
            }
            $source->forceFill(['enabled' => $data['enabled'], 'poll_claim' => null])->save();
            Audit::record('news.source-status', $request->user()->id, context: ['source' => $source->id, 'enabled' => $source->enabled]);
        });

        return redirect('/admin/news')->with('status', 'Source availability updated.');
    }

    public function sourceRights(Request $request, NewsSource $source)
    {
        $data = $request->validate(['reuse' => 'required|in:summary-permitted,link-only,unreviewed', 'reuse_notes' => 'required|string|max:2000', 'version' => 'required|string|size:64']);
        DB::transaction(function () use ($request, $source, $data) {
            $current = NewsSource::lockForUpdate()->findOrFail($source->id);
            $this->checkSourceVersion($current, $data['version']);
            if ($current->reuse === $data['reuse'] && $current->reuse_notes === $data['reuse_notes']) {
                return;
            }
            $current->forceFill(['reuse' => $data['reuse'], 'reuse_notes' => $data['reuse_notes'], 'poll_claim' => null, 'next_poll_at' => null])->save();
            Audit::record('news.source-rights-updated', $request->user()->id, context: ['source' => $current->id, 'reuse' => $data['reuse']]);
        }, attempts: 3);

        return redirect('/admin/news')->with('status', 'Reuse rights updated. Review existing published articles separately.');
    }

    public function sourceFeed(Request $request, NewsSource $source)
    {
        $data = $request->validate(['feed_url' => 'present|nullable|url:https|max:1024', 'version' => 'required|string|size:64']);
        $host = parse_url($source->url, PHP_URL_HOST);
        $url = isset($data['feed_url']) && is_string($host) ? FeedUrl::normalize($data['feed_url'], $host) : null;
        if (isset($data['feed_url']) && $url === null) {
            throw ValidationException::withMessages(['feed_url' => 'Use an HTTPS feed on the approved source hostname.']);
        }

        DB::transaction(function () use ($request, $source, $url, $data) {
            $current = NewsSource::lockForUpdate()->findOrFail($source->id);
            $this->checkSourceVersion($current, $data['version']);
            if ($current->feed_url !== $url) {
                $current->forceFill(['feed_url' => $url, 'next_poll_at' => null, 'poll_claim' => null, 'poll_failures' => 0])->save();
                Audit::record('news.source-feed-updated', $request->user()->id, context: ['source' => $current->id, 'configured' => $url !== null]);
            }
        });

        return redirect('/admin/news')->with('status', 'Source feed saved.');
    }

    private function checkSourceVersion(NewsSource $source, string $version): void
    {
        if (! hash_equals($source->configurationVersion(), $version)) {
            throw ValidationException::withMessages(['version' => 'Source settings changed. Reload before saving.']);
        }
    }

    public function draft(Request $request, News $news)
    {
        $data = $request->validate(['candidate_id' => 'nullable|integer|min:1']);
        $draft = DB::transaction(function () use ($request, $news, $data) {
            if (isset($data['candidate_id'])) {
                $initial = DB::table('news_feed_candidates')->where('id', $data['candidate_id'])->firstOrFail();
                NewsSource::lockForUpdate()->findOrFail($initial->source_id);
                $candidate = $this->feedCandidates(pendingOnly: false)->where('news_feed_candidates.id', $data['candidate_id'])->firstOrFail();
                $request->merge([
                    'source_id' => $candidate->source_id,
                    'source_url' => $candidate->source_url,
                    'external_key' => 'feed:'.$candidate->source_hash,
                ]);
            }

            return $news->intake($request->validate($news->rules()));
        }, attempts: 3);

        return redirect('/admin/news')->with('status', 'Article '.$draft->id.' is available for review.');
    }

    public function candidateReview(Request $request, int $candidate)
    {
        abort_unless(NewsReview::enabled(), 404);
        $data = $request->validate(['decision' => 'required|in:dismiss,restore', 'reason' => 'required|string|min:8|max:300']);
        DB::transaction(function () use ($request, $candidate, $data) {
            $initial = DB::table('news_feed_candidates')->where('id', $candidate)->firstOrFail();
            NewsSource::lockForUpdate()->findOrFail($initial->source_id);
            $row = DB::table('news_feed_candidates')->where('id', $candidate)->lockForUpdate()->firstOrFail();
            abort_if(DB::table('news_articles')->where('source_hash', $row->source_hash)->exists(), 409);
            $existing = DB::table('news_candidate_reviews')->where('candidate_id', $candidate)->first();
            $dismissed = $data['decision'] === 'dismiss';
            abort_if($dismissed === (bool) ($existing->dismissed ?? false), 409);
            DB::table('news_candidate_reviews')->updateOrInsert(['candidate_id' => $candidate], [
                'dismissed' => $dismissed, 'reviewer_id' => $request->user()->id,
                'reason' => $data['reason'], 'updated_at' => now(),
            ]);
            Audit::record('news.candidate-'.$data['decision'], $request->user()->id, context: ['candidate' => $candidate]);
        }, attempts: 3);

        return redirect('/admin/news')->with('status', 'Candidate review saved.');
    }

    private function feedCandidates(bool $pendingOnly = true, bool $dismissed = false): Builder
    {
        abort_if($dismissed && ! NewsReview::enabled(), 404);

        return DB::table('news_feed_candidates')->join('news_sources', 'news_sources.id', '=', 'news_feed_candidates.source_id')
            ->when(NewsReview::enabled(), function ($query) use ($dismissed) {
                $query->leftJoin('news_candidate_reviews', 'news_candidate_reviews.candidate_id', '=', 'news_feed_candidates.id');
                if ($dismissed) {
                    $query->where('news_candidate_reviews.dismissed', true);
                } else {
                    $query->where(fn ($state) => $state->whereNull('news_candidate_reviews.dismissed')->orWhere('news_candidate_reviews.dismissed', false));
                }
            })
            ->select(['news_feed_candidates.*', 'news_sources.name as source_name'])
            ->where('news_sources.enabled', true)->where('news_sources.reuse', 'summary-permitted')
            ->whereNotNull('news_sources.feed_url')
            ->when($pendingOnly, fn (Builder $query) => $query->whereNotExists(fn (Builder $articles) => $articles
                ->selectRaw('1')->from('news_articles')->whereColumn('news_articles.source_hash', 'news_feed_candidates.source_hash')));
    }

    public function editDraft(int $article)
    {
        $item = NewsArticle::with(['source', 'location'])->where('status', 'draft')->findOrFail($article);

        return view('admin.news-draft', compact('item'));
    }

    public function reviseDraft(Request $request, int $article)
    {
        $data = $request->validate([
            'version' => 'required|string|size:64', 'title' => 'required|string|max:140',
            'body' => 'required|string|min:20|max:2500', 'category' => ['required', Rule::in(array_keys(News::CATEGORIES))],
            'sensitive' => 'required|boolean',
        ]);
        DB::transaction(function () use ($request, $article, $data) {
            $initial = NewsArticle::findOrFail($article);
            NewsSource::lockForUpdate()->findOrFail($initial->source_id);
            $item = NewsArticle::lockForUpdate()->findOrFail($article);
            abort_unless($item->status === 'draft', 409);
            if (! hash_equals($item->reviewVersion(), $data['version'])) {
                throw ValidationException::withMessages(['version' => 'Article changed. Reload the draft before saving.']);
            }
            $changes = ['title' => $data['title'], 'body' => $data['body'], 'category' => $data['category'],
                'sensitive' => $item->sensitive || (bool) $data['sensitive'] || in_array($data['category'], ['safety', 'weather'], true)];
            $item->fill($changes);
            if ($item->isDirty()) {
                $this->revision($item, $request->user()->id);
                $item->save();
                Audit::record('news.draft-revised', $request->user()->id, context: ['article' => $article]);
            }
        }, attempts: 3);

        return redirect('/admin/news?status=draft')->with('status', 'Editorial draft saved without publication.');
    }

    public function history(int $article)
    {
        $item = NewsArticle::with('source')->findOrFail($article);
        $revisions = DB::table('news_revisions')->where('article_id', $article)->latest('id')
            ->paginate(15, ['id', 'snapshot', 'created_at']);
        $revisions->through(function ($revision) {
            $snapshot = json_decode($revision->snapshot, true);
            $revision->summary = array_intersect_key(is_array($snapshot) ? $snapshot : [], array_flip(['title', 'body', 'category', 'status', 'sensitive', 'verification']));
            unset($revision->snapshot);

            return $revision;
        });

        return response()->view('admin.news-history', compact('item', 'revisions'))->header('Cache-Control', 'private, no-store');
    }

    public function publish(Request $request, int $article)
    {
        $data = $request->validate([
            'version' => 'required|string|size:64',
            'verification' => 'required|string|min:20|max:3000', 'corroboration_url' => 'nullable|url:https|max:1024',
            'title' => 'nullable|string|max:140', 'body' => 'nullable|string|min:20|max:2500',
        ]);
        DB::transaction(function () use ($article, $request, $data) {
            $initial = NewsArticle::findOrFail($article);
            $source = NewsSource::lockForUpdate()->findOrFail($initial->source_id);
            $item = NewsArticle::lockForUpdate()->findOrFail($article);
            abort_unless(hash_equals($item->reviewVersion(), $data['version']), 409, 'Article changed. Reload before reviewing.');
            abort_unless(in_array($item->status, ['draft', 'published'], true), 409);
            if (! $source->enabled || $source->reuse !== 'summary-permitted') {
                throw ValidationException::withMessages(['verification' => 'Publication requires an enabled source approved for summary reuse.']);
            }
            if ($item->sensitive && (empty($data['corroboration_url'])
                || strcasecmp(parse_url($data['corroboration_url'], PHP_URL_HOST), parse_url($item->source_url, PHP_URL_HOST)) === 0)) {
                throw ValidationException::withMessages(['corroboration_url' => 'Sensitive coverage needs corroboration from another source hostname.']);
            }
            $this->revision($item, $request->user()->id);
            $item->update(array_filter(collect($data)->except('version')->all(), fn ($value) => $value !== null) + [
                'status' => 'published', 'editor_id' => $request->user()->id, 'published_at' => $item->published_at ?? now(),
            ]);
            Audit::record('news.published', $request->user()->id, context: ['article' => $item->id]);
        });

        return redirect('/admin/news')->with('status', 'Article published.');
    }

    public function withdraw(Request $request, int $article)
    {
        $data = $request->validate(['version' => 'required|string|size:64', 'verification' => 'required|string|min:10|max:3000']);
        DB::transaction(function () use ($article, $request, $data) {
            $item = NewsArticle::lockForUpdate()->findOrFail($article);
            abort_unless(hash_equals($item->reviewVersion(), $data['version']), 409, 'Article changed. Reload before reviewing.');
            abort_unless(in_array($item->status, ['draft', 'published'], true), 409);
            $this->revision($item, $request->user()->id);
            $item->update(['status' => 'withdrawn', 'editor_id' => $request->user()->id, 'verification' => $data['verification']]);
            Audit::record('news.withdrawn', $request->user()->id, context: ['article' => $item->id]);
        });

        return redirect('/admin/news')->with('status', 'Article withdrawn.');
    }

    private function revision(NewsArticle $article, int $editor): void
    {
        DB::table('news_revisions')->insert([
            'article_id' => $article->id, 'editor_id' => $editor,
            'snapshot' => json_encode($article->getRawOriginal(), JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);
    }
}
