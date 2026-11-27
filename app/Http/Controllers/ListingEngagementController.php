<?php

namespace App\Http\Controllers;

use App\Models\Listing;
use App\Models\ListingCategory;
use App\Models\Location;
use App\Support\KeywordSearch;
use App\Support\ListingPhotos;
use App\Support\LocationScope;
use App\Support\Marketplace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class ListingEngagementController extends Controller
{
    public function __construct(private readonly Marketplace $marketplace) {}

    public function photo(Request $request, int $listing, int $photo, bool $thumbnail = false)
    {
        $site = $request->attributes->get('site');
        $item = Listing::whereKey($listing)->when($site->location_id, fn ($query, $id) => $query->where('location_id', $id))->firstOrFail();
        $available = $this->marketplace->enabled($site) && $item->status === 'active' && $item->expires_at?->isFuture();
        abort_unless($available || $item->user_id === $request->user()?->id || Gate::allows('manage-network'), 404);
        $media = $item->media()->findOrFail($photo);
        $disk = Storage::disk(config('network.media_disk'));
        $path = $thumbnail ? $media->thumbnail_path : $media->image_path;
        abort_unless(ListingPhotos::readablePath(config('network.media_disk'), 'listings', $path, $thumbnail), 404);
        $stream = $disk->readStream($path);
        abort_unless(is_resource($stream), 404);

        return response()->stream(function () use ($stream) {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, 200, ['Content-Type' => 'image/webp', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function save(Request $request, int $listing)
    {
        $item = $this->visibleListing($request, $listing);
        DB::table('listing_favourites')->insertOrIgnore(['user_id' => $request->user()->id, 'listing_id' => $item->id]);

        return redirect($request->input('return_to') === 'listing' ? LocationScope::detailUrl('/marketplace/'.$item->id) : '/account/saved')->with('status', 'Listing saved.');
    }

    public function remove(Request $request, int $listing)
    {
        if ($request->input('return_to') === 'listing') {
            $this->visibleListing($request, $listing);
        }
        $site = $request->attributes->get('site');
        abort_unless($this->marketplace->enabled($site), 404);
        DB::table('listing_favourites')->where(['user_id' => $request->user()->id, 'listing_id' => $listing])
            ->when($site->location_id, fn ($query, $id) => $query->whereIn('listing_id', Listing::where('location_id', $id)->select('id')))
            ->delete();

        return redirect($request->input('return_to') === 'listing' ? LocationScope::detailUrl('/marketplace/'.$listing) : '/account/saved')->with('status', 'Listing removed from saved items.');
    }

    public function saved(Request $request)
    {
        $site = $request->attributes->get('site');
        abort_unless($this->marketplace->enabled($site), 404);
        $filters = $request->validate(['q' => 'nullable|string|max:100', 'sort' => 'nullable|in:recent,price-low,price-high', 'category' => 'nullable|string|max:64|exists:listing_categories,slug',
            'location' => 'nullable|integer|exists:locations,id']);
        if (! $site->isNetwork()) {
            unset($filters['location']);
        }
        $unavailable = DB::table('listing_favourites')->join('listings', 'listings.id', '=', 'listing_favourites.listing_id')
            ->where('listing_favourites.user_id', $request->user()->id)
            ->when($site->location_id, fn ($query, $id) => $query->where('listings.location_id', $id))
            ->where(function ($query) {
                $query->where('listings.status', '!=', 'active')->orWhereNull('listings.expires_at')->orWhere('listings.expires_at', '<=', now());
            })->orderByDesc('listing_favourites.listing_id')->paginate(15, ['listing_favourites.listing_id'], 'unavailable_page')->appends($filters);
        $matching = $this->marketplace->visible($site)->whereExists(function ($query) use ($request) {
            $query->selectRaw('1')->from('listing_favourites')->whereColumn('listing_id', 'listings.id')->where('user_id', $request->user()->id);
        });
        $total = (clone $matching)->count();
        $categories = ListingCategory::whereIn('slug', (clone $matching)->reorder()->select('category')->distinct())->orderBy('name')->pluck('name', 'slug');
        $places = $site->isNetwork() ? Location::whereIn('id', (clone $matching)->reorder()->select('location_id')->distinct())->orderBy('name')->pluck('name', 'id') : collect();
        $items = $matching->when(filled($filters['q'] ?? null), fn ($query) => KeywordSearch::apply($query, $filters['q'], ['title', 'description']))
            ->when($filters['category'] ?? null, fn ($query, $category) => $query->where('category', $category))
            ->when($filters['location'] ?? null, fn ($query, $id) => $query->where('location_id', $id));
        match ($filters['sort'] ?? 'recent') {
            'price-low' => $items->orderBy('price_cents'),
            'price-high' => $items->orderByDesc('price_cents'),
            default => $items->latest('published_at'),
        };
        $items = $items->latest('id')->paginate(18)->appends($filters);
        if ($items->currentPage() > 1) {
            $unavailable->appends(['page' => $items->currentPage()]);
        }
        if ($unavailable->currentPage() > 1) {
            $items->appends(['unavailable_page' => $unavailable->currentPage()]);
        }

        return view('marketplace.saved', compact('items', 'filters', 'total', 'unavailable', 'categories', 'places'));
    }

    public function clearUnavailable(Request $request)
    {
        $site = $request->attributes->get('site');
        abort_unless($this->marketplace->enabled($site), 404);
        $request->validate(['confirm_remove' => 'accepted']);
        $unavailable = Listing::query()->when($site->location_id, fn ($query, $id) => $query->where('location_id', $id))
            ->where(fn ($query) => $query->where('status', '!=', 'active')->orWhereNull('expires_at')->orWhere('expires_at', '<=', now()))
            ->select('id');
        $removed = DB::table('listing_favourites')->where('user_id', $request->user()->id)
            ->whereIn('listing_id', $unavailable)->delete();

        return redirect('/account/saved')->with('status', $removed.' unavailable saved listings removed.');
    }

    public function reports(Request $request)
    {
        $site = $request->attributes->get('site');
        $filters = $request->validate(['status' => 'nullable|in:pending,resolved', 'reference' => 'nullable|integer|min:1']);
        $owned = DB::table('listing_reports')->join('listings', 'listings.id', '=', 'listing_reports.listing_id')
            ->where('listing_reports.reporter_id', $request->user()->id)
            ->when($site->location_id, fn ($query, $id) => $query->where('listings.location_id', $id))
            ->when($filters['reference'] ?? null, fn ($query, $id) => $query->where('listing_reports.id', $id));
        $pending = (clone $owned)->whereNull('listing_reports.resolved_at')->count();
        $resolved = (clone $owned)->whereNotNull('listing_reports.resolved_at')->count();
        $items = $owned->when(($filters['status'] ?? null) === 'pending', fn ($query) => $query->whereNull('listing_reports.resolved_at'))
            ->when(($filters['status'] ?? null) === 'resolved', fn ($query) => $query->whereNotNull('listing_reports.resolved_at'))
            ->select('listing_reports.id', 'listing_reports.listing_id', 'listing_reports.reason', 'listing_reports.created_at', 'listing_reports.resolved_at')
            ->selectRaw("CASE WHEN listings.status = 'active' AND listings.expires_at > ? THEN listings.title ELSE NULL END AS public_title", [now()])
            ->latest('listing_reports.id')->paginate(20)->appends($filters);
        $marketEnabled = $this->marketplace->enabled($site);

        return view('marketplace.reports', compact('items', 'marketEnabled', 'filters', 'pending', 'resolved'));
    }

    public function report(Request $request, int $listing)
    {
        $item = $this->visibleListing($request, $listing);
        abort_if($item->user_id === $request->user()->id, 403);
        $input = $request->validate(['reason' => 'required|string|min:8|max:500']);
        $created = DB::table('listing_reports')->insertOrIgnore([
            'listing_id' => $item->id, 'reporter_id' => $request->user()->id, 'reason' => $input['reason'],
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return redirect(LocationScope::detailUrl('/marketplace/'.$item->id))->with('status', $created ? 'Your report was submitted for review.' : 'You have already reported this listing.');
    }

    private function visibleListing(Request $request, int $id): Listing
    {
        $site = $request->attributes->get('site');
        abort_unless($this->marketplace->enabled($site), 404);

        return $this->marketplace->visible($site)->findOrFail($id);
    }
}
