<?php

namespace App\Http\Controllers;

use App\Models\Location;
use App\Models\PropertyListing;
use App\Models\Site;
use App\Support\KeywordSearch;
use App\Support\Marketplace;
use App\Support\NetworkUrl;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class RealEstateController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'q' => 'nullable|string|max:100',
            'transaction' => 'nullable|in:sale,rent',
            'property_type' => ['nullable', Rule::in(array_keys(PropertyListing::TYPES))],
            'location' => ['nullable', 'string', 'max:80', Rule::exists('locations', 'slug')
                ->where('province', 'MB')->where('selectable', true)],
            'min_price' => 'nullable|numeric|decimal:0,2|min:0|max:1000000000',
            'max_price' => 'nullable|numeric|decimal:0,2|min:0|max:1000000000',
            'bedrooms' => 'nullable|integer|min:0|max:100',
            'sort' => 'nullable|in:newest,price_asc,price_desc,largest',
        ]);
        $site = $request->attributes->get('site');
        $locations = $site->isNetwork() ? Location::selectable()->orderBy('name')->get() : collect();
        $selectedLocation = $site->isNetwork() && isset($filters['location'])
            ? $locations->firstWhere('slug', $filters['location'])
            : null;
        if (! $site->isNetwork()) {
            unset($filters['location']);
        }

        $query = $this->visible($request)
            ->when($selectedLocation, fn (Builder $query, Location $place) => $query->where('location_id', $place->id))
            ->when($filters['q'] ?? null, fn (Builder $query, string $term) => KeywordSearch::apply($query, $term, ['title', 'description']))
            ->when($filters['transaction'] ?? null, fn (Builder $query, string $value) => $query->where('transaction', $value))
            ->when($filters['property_type'] ?? null, fn (Builder $query, string $value) => $query->where('property_type', $value))
            ->when(isset($filters['min_price']), fn (Builder $query) => $query->where('price_cents', '>=', (int) round($filters['min_price'] * 100)))
            ->when(isset($filters['max_price']), fn (Builder $query) => $query->where('price_cents', '<=', (int) round($filters['max_price'] * 100)))
            ->when(isset($filters['bedrooms']), fn (Builder $query) => $query->where('bedrooms', '>=', $filters['bedrooms']));

        match ($filters['sort'] ?? 'newest') {
            'price_asc' => $query->orderBy('price_cents')->orderByDesc('id'),
            'price_desc' => $query->orderByDesc('price_cents')->orderByDesc('id'),
            'largest' => $query->orderByDesc('floor_area')->orderByDesc('id'),
            default => $query->latest('id'),
        };

        $items = $query->paginate(18)->withQueryString();
        $publicEnabled = (bool) config('network.real_estate_public_listings');

        return view('real-estate.index', compact('items', 'filters', 'locations', 'selectedLocation', 'publicEnabled'));
    }

    public function show(Request $request, int $property)
    {
        $item = $this->visible($request)->findOrFail($property);
        $site = $request->attributes->get('site');
        $local = Site::with('domain')->where('location_id', $item->location_id)->where('status', 'active')->first();
        $canonicalSite = $local && app(Marketplace::class)->enabled($local) ? $local : NetworkUrl::main();
        $canonical = NetworkUrl::to($canonicalSite, '/real-estate/'.$item->id);
        if (parse_url($canonical, PHP_URL_HOST) !== $site->domain->host) {
            return redirect()->away($canonical, 301, ['Cache-Control' => 'no-store']);
        }

        return view('real-estate.show', compact('item', 'canonical'));
    }

    public function photo(Request $request, int $property, int $photo)
    {
        $item = $this->visible($request)->findOrFail($property);
        $media = $item->photos()->findOrFail($photo);
        $disk = Storage::disk('local');
        abort_unless($disk->exists($media->thumbnail_path), 404);

        return $disk->response($media->thumbnail_path, 'property-photo.webp', [
            'Content-Type' => 'image/webp',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * No live property can appear without an explicit separate rollout flag.
     * The existing draft and review flows NEVER assign the published status.
     */
    private function visible(Request $request): Builder
    {
        $site = $request->attributes->get('site');
        abort_unless(app(Marketplace::class)->enabled($site), 404);

        return PropertyListing::query()->with(['location', 'photos'])
            ->where('status', 'published')
            ->when(! config('network.real_estate_public_listings'), fn (Builder $query) => $query->whereRaw('1 = 0'))
            ->when($site->location_id !== null, fn (Builder $query) => $query->where('location_id', $site->location_id));
    }
}
