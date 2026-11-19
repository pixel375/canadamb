<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\NewsArticle;
use App\Models\NewsSource;
use App\Support\Audit;
use App\Support\News;
use App\Support\NewsFeeds\FeedUrl;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EditorialController extends Controller
{
    public function index(Request $request)
    {
        $input = $request->validate(['candidate' => 'nullable|integer|min:1']);

        return view('admin.editorial', [
            'articles' => NewsArticle::with(['source', 'location'])->whereIn('status', ['draft', 'published'])
                ->orderByRaw("CASE WHEN status = 'draft' THEN 0 ELSE 1 END")->latest()->paginate(12),
            'sources' => NewsSource::orderBy('name')->get(),
            'places' => Location::selectable()->orderBy('name')->get(),
            'feedCandidates' => $this->feedCandidates()->orderByDesc('news_feed_candidates.id')->paginate(12, pageName: 'feeds'),
            'candidate' => isset($input['candidate'])
                ? $this->feedCandidates()->where('news_feed_candidates.id', $input['candidate'])->firstOrFail()
                : null,
            'feedChecks' => DB::table('news_feed_checks')->join('news_sources', 'news_sources.id', '=', 'news_feed_checks.source_id')
                ->latest('news_feed_checks.id')->limit(20)->get(['news_feed_checks.*', 'news_sources.name']),
        ]);
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
        $data = $request->validate(['enabled' => 'required|boolean']);
        DB::transaction(function () use ($request, $source, $data) {
            $source->forceFill($data + ['poll_claim' => null])->save();
            Audit::record('news.source-status', $request->user()->id, context: ['source' => $source->id, 'enabled' => $source->enabled]);
        });

        return redirect('/admin/news')->with('status', 'Source availability updated.');
    }

    public function sourceFeed(Request $request, NewsSource $source)
    {
        $data = $request->validate(['feed_url' => 'present|nullable|url:https|max:1024']);
        $host = parse_url($source->url, PHP_URL_HOST);
        $url = isset($data['feed_url']) && is_string($host) ? FeedUrl::normalize($data['feed_url'], $host) : null;
        if (isset($data['feed_url']) && $url === null) {
            throw ValidationException::withMessages(['feed_url' => 'Use an HTTPS feed on the approved source hostname.']);
        }

        DB::transaction(function () use ($request, $source, $url) {
            $current = NewsSource::lockForUpdate()->findOrFail($source->id);
            if ($current->feed_url !== $url) {
                $current->forceFill(['feed_url' => $url, 'next_poll_at' => null, 'poll_claim' => null, 'poll_failures' => 0])->save();
                Audit::record('news.source-feed-updated', $request->user()->id, context: ['source' => $current->id, 'configured' => $url !== null]);
            }
        });

        return redirect('/admin/news')->with('status', 'Source feed saved.');
    }

    public function draft(Request $request, News $news)
    {
        $data = $request->validate(['candidate_id' => 'nullable|integer|min:1']);
        if (isset($data['candidate_id'])) {
            $candidate = $this->feedCandidates(pendingOnly: false)->where('news_feed_candidates.id', $data['candidate_id'])->firstOrFail();
            $request->merge([
                'source_id' => $candidate->source_id,
                'source_url' => $candidate->source_url,
                'external_key' => 'feed:'.$candidate->source_hash,
            ]);
        }
        $draft = $news->intake($request->validate($news->rules()));

        return redirect('/admin/news')->with('status', 'Article '.$draft->id.' is available for review.');
    }

    private function feedCandidates(bool $pendingOnly = true): Builder
    {
        return DB::table('news_feed_candidates')->join('news_sources', 'news_sources.id', '=', 'news_feed_candidates.source_id')
            ->select(['news_feed_candidates.*', 'news_sources.name as source_name'])
            ->where('news_sources.enabled', true)->where('news_sources.reuse', 'summary-permitted')
            ->whereNotNull('news_sources.feed_url')
            ->when($pendingOnly, fn (Builder $query) => $query->whereNotExists(fn (Builder $articles) => $articles
                ->selectRaw('1')->from('news_articles')->whereColumn('news_articles.source_hash', 'news_feed_candidates.source_hash')));
    }

    public function publish(Request $request, int $article)
    {
        $data = $request->validate([
            'verification' => 'required|string|min:20|max:3000', 'corroboration_url' => 'nullable|url:https|max:1024',
            'title' => 'nullable|string|max:140', 'body' => 'nullable|string|min:20|max:2500',
        ]);
        DB::transaction(function () use ($article, $request, $data) {
            $item = NewsArticle::with('source')->lockForUpdate()->findOrFail($article);
            abort_unless(in_array($item->status, ['draft', 'published'], true), 409);
            if (! $item->source->enabled || $item->source->reuse !== 'summary-permitted') {
                throw ValidationException::withMessages(['verification' => 'Publication requires an enabled source approved for summary reuse.']);
            }
            if ($item->sensitive && (empty($data['corroboration_url'])
                || strcasecmp(parse_url($data['corroboration_url'], PHP_URL_HOST), parse_url($item->source_url, PHP_URL_HOST)) === 0)) {
                throw ValidationException::withMessages(['corroboration_url' => 'Sensitive coverage needs corroboration from another source hostname.']);
            }
            $this->revision($item, $request->user()->id);
            $item->update(array_filter($data, fn ($value) => $value !== null) + [
                'status' => 'published', 'editor_id' => $request->user()->id, 'published_at' => $item->published_at ?? now(),
            ]);
            Audit::record('news.published', $request->user()->id, context: ['article' => $item->id]);
        });

        return redirect('/admin/news')->with('status', 'Article published.');
    }

    public function withdraw(Request $request, int $article)
    {
        $data = $request->validate(['verification' => 'required|string|min:10|max:3000']);
        DB::transaction(function () use ($article, $request, $data) {
            $item = NewsArticle::lockForUpdate()->findOrFail($article);
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
            'snapshot' => json_encode($article->getAttributes(), JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);
    }
}
