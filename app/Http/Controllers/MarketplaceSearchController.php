<?php

namespace App\Http\Controllers;

use App\Models\ListingCategory;
use App\Models\MarketplaceSearch as SavedSearch;
use App\Models\User;
use App\Support\MarketplaceCategories;
use App\Support\MarketplaceSearch;
use App\Support\MarketplaceSearches;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MarketplaceSearchController extends Controller
{
    public function index(Request $request)
    {
        $site = $request->attributes->get('site');
        $items = $this->owned($request)->latest('id')->get();
        $categories = ListingCategory::pluck('name', 'slug')->all();
        $counts = $items->mapWithKeys(fn (SavedSearch $item) => [$item->id => MarketplaceSearch::matching($site, $item->filters, $categories)->count()]);

        return view('marketplace.searches', compact('items', 'counts'));
    }

    public function store(Request $request)
    {
        $this->owned($request);
        $site = $request->attributes->get('site');
        $data = $request->validate(['name' => 'required|string|max:80']);
        $categories = ListingCategory::pluck('name', 'slug')->all();
        $filters = MarketplaceSearch::filters($request, $site, MarketplaceCategories::grouped($categories));
        unset($filters['view'], $filters['sort']);
        foreach (['min_price', 'max_price'] as $price) {
            if (isset($filters[$price])) {
                $filters[$price] = number_format((float) $filters[$price], 2, '.', '');
            }
        }
        ksort($filters);
        $key = hash('sha256', json_encode($filters, JSON_THROW_ON_ERROR));
        DB::transaction(function () use ($request, $site, $data, $filters, $key) {
            $member = $request->user()->id;
            User::lockForUpdate()->findOrFail($member);
            $owned = SavedSearch::where('user_id', $member);
            if ((clone $owned)->where('site_id', $site->id)->where('filter_key', $key)->exists()) {
                throw ValidationException::withMessages(['name' => 'You already saved these filters on this portal.']);
            }
            if ($owned->count() >= 15) {
                throw ValidationException::withMessages(['name' => 'You can save up to 15 Marketplace searches across the network.']);
            }
            SavedSearch::create([
                'user_id' => $member, 'site_id' => $site->id, 'name' => $data['name'], 'filters' => $filters, 'filter_key' => $key,
            ]);
        }, attempts: 3);

        return $this->done();
    }

    public function rename(Request $request, int $search)
    {
        $item = $this->owned($request)->findOrFail($search);
        $item->update($request->validate(['name' => 'required|string|max:80']));

        return $this->done();
    }

    public function destroy(Request $request, int $search)
    {
        $this->owned($request)->findOrFail($search)->delete();

        return $this->done();
    }

    private function owned(Request $request)
    {
        $site = $request->attributes->get('site');
        abort_unless(MarketplaceSearches::enabled($site), 404);

        return SavedSearch::where('user_id', $request->user()->id)->where('site_id', $site->id);
    }

    private function done()
    {
        return redirect('/account/marketplace-searches')->with('status', 'Saved Marketplace searches updated.');
    }
}
