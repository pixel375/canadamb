<?php

namespace App\Http\Controllers;

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
        $filter = $request->validate(['category' => ['nullable', Rule::in(array_keys(News::CATEGORIES))]]);
        $items = $news->visible($site->location_id)
            ->when($filter['category'] ?? null, fn ($query, $category) => $query->where('category', $category))
            ->paginate(18)->withQueryString();

        return view('news.index', compact('items', 'filter'));
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

        return view('news.show', compact('item', 'canonical'));
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
