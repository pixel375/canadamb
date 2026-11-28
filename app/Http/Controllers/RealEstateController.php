<?php

namespace App\Http\Controllers;

use App\Models\Location;
use App\Models\PropertyListing;
use App\Support\KeywordSearch;
use App\Support\LocationScope;
use App\Support\NetworkUrl;
use App\Support\PublicProperties;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
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
            'max_price' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:1000000000', Rule::when($request->filled('min_price'), 'gte:min_price')],
            'bedrooms' => 'nullable|integer|min:0|max:100',
            'bathrooms' => 'nullable|numeric|decimal:0,1|multiple_of:0.5|min:0|max:100',
            'min_floor_area' => 'nullable|integer|min:0|max:10000000',
            'min_acreage' => 'nullable|numeric|decimal:0,4|min:0|max:10000000',
            'year_min' => 'nullable|integer|min:1600|max:'.(now()->year + 5),
            'available_by' => 'nullable|date_format:Y-m-d',
            'pets' => 'nullable|in:allowed',
            'sort' => 'nullable|in:newest,price_asc,price_desc,largest',
        ]);
        $site = $request->attributes->get('site');
        $locations = $site->isNetwork() ? Location::selectable()->orderBy('name')->get() : collect();
        $selectedLocation = $site->isNetwork() && isset($filters['location'])
            ? $locations->firstWhere('slug', $filters['location'])
            : null;
        if ($site->isNetwork() === false) {
            unset($filters['location']);
        }

        $query = $this->visible($request)
            ->when($selectedLocation, fn (Builder $query, Location $place) => $query->where('location_id', $place->id))
            ->when(filled($filters['q'] ?? null), fn (Builder $query) => KeywordSearch::apply($query, $filters['q'], ['title', 'description']))
            ->when($filters['transaction'] ?? null, fn (Builder $query, string $value) => $query->where('transaction', $value))
            ->when($filters['property_type'] ?? null, fn (Builder $query, string $value) => $query->where('property_type', $value))
            ->when(isset($filters['min_price']), fn (Builder $query) => $query->where('price_cents', '>=', (int) round($filters['min_price'] * 100)))
            ->when(isset($filters['max_price']), fn (Builder $query) => $query->where('price_cents', '<=', (int) round($filters['max_price'] * 100)))
            ->when(isset($filters['bedrooms']), fn (Builder $query) => $query->where('bedrooms', '>=', $filters['bedrooms']))
            ->when(isset($filters['bathrooms']), fn (Builder $query) => $query->where('bathrooms', '>=', $filters['bathrooms']))
            ->when(isset($filters['min_floor_area']), fn (Builder $query) => $query->where('floor_area', '>=', $filters['min_floor_area']))
            ->when(isset($filters['min_acreage']), fn (Builder $query) => $query->where('acreage', '>=', $filters['min_acreage']))
            ->when(isset($filters['year_min']), fn (Builder $query) => $query->where('year_built', '>=', $filters['year_min']))
            ->when(isset($filters['available_by']), fn (Builder $query) => $query->where('available_on', '<=', $filters['available_by']))
            ->when(isset($filters['pets']), fn (Builder $query) => $query->where('transaction', 'rent')->where('pets_allowed', true));

        match ($filters['sort'] ?? 'newest') {
            'price_asc' => $query->orderBy('price_cents')->orderByDesc('id'),
            'price_desc' => $query->orderByDesc('price_cents')->orderByDesc('id'),
            'largest' => $query->orderByDesc('floor_area')->orderByDesc('id'),
            default => $query->latest('id'),
        };

        $items = $query->paginate(18)->appends(LocationScope::preserveAll($filters));
        $publicEnabled = (bool) config('network.real_estate_public_listings');

        return view('real-estate.index', compact('items', 'filters', 'locations', 'selectedLocation', 'publicEnabled'));
    }

    public function show(Request $request, int $property)
    {
        $item = $this->visible($request)->findOrFail($property);
        $canonical = NetworkUrl::to(PublicProperties::canonicalSite($item), '/real-estate/'.$item->id);

        return view('real-estate.show', compact('item', 'canonical'));
    }

    public function photo(Request $request, int $property, int $photo)
    {
        $item = $this->visible($request)->findOrFail($property);

        return PropertyDraftController::privatePhoto($item, $photo);
    }

    /**
     * No live property can appear without an explicit separate rollout flag.
     * The existing draft and review flows NEVER assign the published status.
     */
    private function visible(Request $request): Builder
    {
        $site = $request->attributes->get('site');
        abort_unless(PublicProperties::moduleEnabled($site), 404);

        return PublicProperties::visible($site)->with(['location', 'photos']);
    }
}
