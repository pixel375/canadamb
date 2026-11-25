<?php

namespace App\Http\Controllers;

use App\Models\Listing;
use App\Support\KeywordSearch;
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
        abort_unless(preg_match('~^listings/[a-f0-9-]{36}(-preview)?\\.webp$~D', $path), 404);
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

        return redirect($request->input('return_to') === 'listing' ? '/marketplace/'.$item->id : '/account/saved')->with('status', 'Listing saved.');
    }

    public function remove(Request $request, int $listing)
    {
        if ($request->input('return_to') === 'listing') {
            $this->visibleListing($request, $listing);
        }
        DB::table('listing_favourites')->where(['user_id' => $request->user()->id, 'listing_id' => $listing])->delete();

        return redirect($request->input('return_to') === 'listing' ? '/marketplace/'.$listing : '/account/saved')->with('status', 'Listing removed from saved items.');
    }

    public function saved(Request $request)
    {
        $site = $request->attributes->get('site');
        abort_unless($this->marketplace->enabled($site), 404);
        $filters = $request->validate(['q' => 'nullable|string|max:100', 'sort' => 'nullable|in:recent,price-low,price-high']);
        $unavailable = DB::table('listing_favourites')->join('listings', 'listings.id', '=', 'listing_favourites.listing_id')
            ->where('listing_favourites.user_id', $request->user()->id)
            ->when($site->location_id, fn ($query, $id) => $query->where('listings.location_id', $id))
            ->where(function ($query) {
                $query->where('listings.status', '!=', 'active')->orWhereNull('listings.expires_at')->orWhere('listings.expires_at', '<=', now());
            })->orderByDesc('listing_favourites.listing_id')->paginate(15, ['listing_favourites.listing_id'], 'unavailable_page')->withQueryString();
        $matching = $this->marketplace->visible($site)->whereExists(function ($query) use ($request) {
            $query->selectRaw('1')->from('listing_favourites')->whereColumn('listing_id', 'listings.id')->where('user_id', $request->user()->id);
        });
        $total = (clone $matching)->count();
        $items = $matching->when(filled($filters['q'] ?? null), fn ($query) => KeywordSearch::apply($query, $filters['q'], ['title', 'description']));
        match ($filters['sort'] ?? 'recent') {
            'price-low' => $items->orderBy('price_cents'),
            'price-high' => $items->orderByDesc('price_cents'),
            default => $items->latest('published_at'),
        };
        $items = $items->latest('id')->paginate(18)->withQueryString();

        return view('marketplace.saved', compact('items', 'filters', 'total', 'unavailable'));
    }

    public function clearUnavailable(Request $request)
    {
        $request->validate(['confirm_remove' => 'accepted']);
        $site = $request->attributes->get('site');
        abort_unless($this->marketplace->enabled($site), 404);
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
        $items = DB::table('listing_reports')->join('listings', 'listings.id', '=', 'listing_reports.listing_id')
            ->where('listing_reports.reporter_id', $request->user()->id)
            ->when($site->location_id, fn ($query, $id) => $query->where('listings.location_id', $id))
            ->select('listing_reports.id', 'listing_reports.listing_id', 'listing_reports.reason', 'listing_reports.created_at', 'listing_reports.resolved_at')
            ->selectRaw("CASE WHEN listings.status = 'active' AND listings.expires_at > ? THEN listings.title ELSE NULL END AS public_title", [now()])
            ->latest('listing_reports.id')->paginate(20);
        $marketEnabled = $this->marketplace->enabled($site);

        return view('marketplace.reports', compact('items', 'marketEnabled'));
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

        return redirect('/marketplace/'.$item->id)->with('status', $created ? 'Your report was submitted for review.' : 'You have already reported this listing.');
    }

    private function visibleListing(Request $request, int $id): Listing
    {
        $site = $request->attributes->get('site');
        abort_unless($this->marketplace->enabled($site), 404);

        return $this->marketplace->visible($site)->findOrFail($id);
    }
}
