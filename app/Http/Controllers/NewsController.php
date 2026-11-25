<?php

namespace App\Http\Controllers;

use App\Models\Location;
use App\Models\NewsSource;
use App\Support\KeywordSearch;
use App\Support\LocationScope;
use App\Support\NetworkUrl;
use App\Support\News;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class NewsController extends Controller
{
    public function index(Request $request, News $news)
    {
        $site = $request->attributes->get('site');
        abort_unless($news->enabled($site), 404);
        $filter = $request->validate([
            'q' => 'nullable|string|max:100',
            'category' => ['nullable', Rule::in(array_keys(News::CATEGORIES))],
            'source' => 'nullable|integer|min:1',
            'period' => 'nullable|in:7,30,90',
            'location' => ['nullable', 'string', 'max:100', Rule::exists('locations', 'slug')->where('province', 'MB')->where('selectable', true)],
        ]);
        $place = LocationScope::forBrowsing($site, $filter['location'] ?? null);
        $places = $site->isNetwork() ? Location::selectable()->orderBy('name')->get() : collect();
        $visible = $news->visible($place?->id);
        $sources = NewsSource::whereIn('id', (clone $visible)->reorder()->select('source_id'))
            ->orderBy('name')->get(['id', 'name']);
        $matching = $visible
            ->when($filter['q'] ?? null, fn ($query, $term) => KeywordSearch::apply($query, $term, ['title', 'body']))
            ->when($filter['source'] ?? null, fn ($query, $source) => $query->where('source_id', $source))
            ->when($filter['period'] ?? null, fn ($query, $period) => $query->where('published_at', '>=', now()->subDays((int) $period)));
        $categoryCounts = (clone $matching)->reorder()->selectRaw('category, COUNT(*) as total')->groupBy('category')->pluck('total', 'category');
        $items = $matching->when($filter['category'] ?? null, fn ($query, $category) => $query->where('category', $category))
            ->paginate(18)->withQueryString();

        return view('news.index', compact('items', 'filter', 'place', 'places', 'sources', 'categoryCounts'));
    }

    public function show(Request $request, int $article, News $news)
    {
        $site = $request->attributes->get('site');
        abort_unless($news->enabled($site), 404);
        $item = $news->visible($site->location_id)->findOrFail($article);
        $owner = $news->canonicalSite($item);
        $canonical = NetworkUrl::to($owner, '/news/'.$item->id);
        if ($owner->id !== $site->id) {
            return redirect()->away($canonical, 301, ['Cache-Control' => 'no-store']);
        }

        $related = $news->visible($item->location_id)->where('location_id', $item->location_id)
            ->where('category', $item->category)->whereKeyNot($item->id)->limit(4)->get();

        return view('news.show', compact('item', 'canonical', 'related'));
    }

    public function ingest(Request $request, News $news)
    {
        abort_unless($request->attributes->get('site')->isNetwork(), 404);
        $secret = (string) config('network.news_ingest_token');
        abort_unless(strlen($secret) >= 32 && hash_equals($secret, (string) $request->bearerToken()), 401);
        $draft = $news->intake($request->validate($news->rules()));

        return response()->json(['id' => $draft->id, 'status' => $draft->status], $draft->wasRecentlyCreated ? 201 : 200);
    }
}
