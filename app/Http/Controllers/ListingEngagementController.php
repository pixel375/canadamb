<?php

namespace App\Http\Controllers;

use App\Models\Listing;
use App\Models\ListingMedia;
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
        $enabled = $this->marketplace->enabled($site);
        $viewer = $request->user();
        $verified = $viewer?->hasVerifiedEmail();
        abort_unless($enabled || $verified, 404);
        $listings = Listing::whereKey($listing)->select('id')
            ->when($site->location_id !== null, fn ($query) => $query->where('location_id', $site->location_id));
        if (! $verified || Gate::denies('manage-network')) {
            $listings->where(function ($scope) use ($enabled, $viewer, $verified) {
                if ($enabled) {
                    $scope->active();
                }
                if ($verified) {
                    $scope->orWhere('user_id', $viewer->id);
                }
            });
        }
        // Resolve media and listing access in one read so a simultaneous edit cannot expose newly pending media.
        $media = ListingMedia::whereIn('listing_id', $listings)->findOrFail($photo);
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
        DB::transaction(function () use ($request, $listing) {
            $item = $this->visibleListingForUpdate($request, $listing);
            DB::table('listing_favourites')->insertOrIgnore(['user_id' => $request->user()->id, 'listing_id' => $item->id]);
        });
        $path = $request->input('return_to') === 'listing' ? '/marketplace/'.$listing : '/account/saved';

        return redirect($path)->with('status', 'Listing saved.');
    }

    public function remove(Request $request, int $listing)
    {
        $site = $request->attributes->get('site');
        abort_unless($this->marketplace->enabled($site), 404);
        $inPortal = Listing::query()->select('id')->whereKey($listing)
            ->when($site->location_id !== null, fn ($query) => $query->where('location_id', $site->location_id));
        $removed = DB::table('listing_favourites')->where(['user_id' => $request->user()->id, 'listing_id' => $listing])
            ->whereIn('listing_id', $inPortal)->delete();
        $available = $this->marketplace->visible($site)->whereKey($listing)->exists();
        abort_unless($removed || $available, 404);
        $show = $request->input('return_to') === 'listing' && $available;

        return redirect($show ? '/marketplace/'.$listing : '/account/saved')->with('status', 'Listing removed from saved items.');
    }

    public function saved(Request $request)
    {
        $site = $request->attributes->get('site');
        abort_unless($this->marketplace->enabled($site), 404);
        $items = $this->marketplace->visible($site)->whereExists(function ($query) use ($request) {
            $query->selectRaw('1')->from('listing_favourites')->whereColumn('listing_id', 'listings.id')->where('user_id', $request->user()->id);
        })->latest('published_at')->latest('id')->paginate(18);

        return view('marketplace.saved', compact('items'));
    }

    public function report(Request $request, int $listing)
    {
        $created = DB::transaction(function () use ($request, $listing) {
            $item = $this->visibleListingForUpdate($request, $listing);
            abort_if($item->user_id === $request->user()->id, 403);
            $input = $request->validate(['reason' => 'required|string|min:8|max:500']);

            return DB::table('listing_reports')->insertOrIgnore([
                'listing_id' => $item->id, 'reporter_id' => $request->user()->id, 'reason' => $input['reason'],
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });
        $message = $created ? 'Your report was submitted for review.' : 'You have already reported this listing.';

        return redirect('/marketplace/'.$listing)->with('status', $message);
    }

    private function visibleListingForUpdate(Request $request, int $id): Listing
    {
        $site = $request->attributes->get('site');
        abort_unless($this->marketplace->enabled($site), 404);

        return $this->marketplace->visible($site)->without(['location', 'categoryRecord', 'media'])
            ->lockForUpdate()->findOrFail($id, ['id', 'user_id']);
    }
}
