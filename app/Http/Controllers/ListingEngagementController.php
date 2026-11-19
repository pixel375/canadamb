<?php

namespace App\Http\Controllers;

use App\Models\Listing;
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
        $available = $this->marketplace->enabled($site) && $item->status === 'active' && $item->expires_at->isFuture();
        abort_unless($available || $item->user_id === $request->user()?->id || Gate::allows('manage-network'), 404);
        $media = $item->media()->findOrFail($photo);
        $disk = Storage::disk(config('network.media_disk'));
        $stream = $disk->readStream($thumbnail ? $media->thumbnail_path : $media->image_path);
        abort_unless(is_resource($stream), 404);

        return response()->stream(function () use ($stream) {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, 200, ['Content-Type' => 'image/webp', 'Cache-Control' => 'private, no-store']);
    }

    public function save(Request $request, int $listing)
    {
        $item = $this->visibleListing($request, $listing);
        DB::table('listing_favourites')->insertOrIgnore(['user_id' => $request->user()->id, 'listing_id' => $item->id]);

        return redirect('/account/saved')->with('status', 'Listing saved.');
    }

    public function remove(Request $request, int $listing)
    {
        DB::table('listing_favourites')->where(['user_id' => $request->user()->id, 'listing_id' => $listing])->delete();

        return redirect('/account/saved')->with('status', 'Listing removed from saved items.');
    }

    public function saved(Request $request)
    {
        $site = $request->attributes->get('site');
        abort_unless($this->marketplace->enabled($site), 404);
        $items = $this->marketplace->visible($site)->whereExists(function ($query) use ($request) {
            $query->selectRaw('1')->from('listing_favourites')->whereColumn('listing_id', 'listings.id')->where('user_id', $request->user()->id);
        })->latest('published_at')->paginate(18);

        return view('marketplace.saved', compact('items'));
    }

    public function report(Request $request, int $listing)
    {
        $item = $this->visibleListing($request, $listing);
        abort_if($item->user_id === $request->user()->id, 403);
        $input = $request->validate(['reason' => 'required|string|min:8|max:500']);
        DB::table('listing_reports')->insertOrIgnore([
            'listing_id' => $item->id, 'reporter_id' => $request->user()->id, 'reason' => $input['reason'],
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return redirect('/marketplace/'.$item->id)->with('status', 'Your report was submitted for review.');
    }

    private function visibleListing(Request $request, int $id): Listing
    {
        $site = $request->attributes->get('site');
        abort_unless($this->marketplace->enabled($site), 404);

        return $this->marketplace->visible($site)->findOrFail($id);
    }
}
