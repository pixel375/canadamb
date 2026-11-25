<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveListing;
use App\Models\Conversation;
use App\Models\Listing;
use App\Models\ListingCategory;
use App\Models\Location;
use App\Support\KeywordSearch;
use App\Support\ListingPhotos;
use App\Support\LocationScope;
use App\Support\Marketplace;
use App\Support\MarketplaceCategories;
use App\Support\NetworkUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class MarketplaceController extends Controller
{
    private const SORT_OPTIONS = [
        'newest' => 'Newest',
        'price_asc' => 'Price: low to high',
        'price_desc' => 'Price: high to low',
    ];

    public function __construct(private readonly Marketplace $marketplace) {}

    public function index(Request $request)
    {
        $site = $request->attributes->get('site');
        abort_unless($this->marketplace->enabled($site), 404);
        $categories = $this->categories();
        $categoryGroups = MarketplaceCategories::grouped($categories->all());
        $priceRules = ['bail', 'nullable', 'numeric', 'decimal:0,2', 'between:0,9999999.99'];
        $rules = [
            'q' => 'nullable|string|max:100',
            'category' => 'bail|nullable|string|max:64|exists:listing_categories,slug',
            'group' => ['nullable', 'string', Rule::in(array_keys($categoryGroups))],
            'view' => 'nullable|in:all',
            'condition' => ['nullable', 'string', Rule::in(array_keys(Marketplace::CONDITIONS))],
            'min_price' => $priceRules,
            'max_price' => [...$priceRules, Rule::when($request->filled('min_price'), 'gte:min_price')],
            'sort' => ['nullable', 'string', Rule::in(array_keys(self::SORT_OPTIONS))],
        ];
        if ($site->isNetwork()) {
            $rules['location'] = ['bail', 'nullable', 'string', 'max:100', Rule::exists(Location::class, 'slug')->where('province', 'MB')->where('selectable', true)];
        }
        $filters = array_filter($request->validate($rules), fn ($value) => $value !== null && $value !== '');
        if (($filters['sort'] ?? null) === 'newest') {
            unset($filters['sort']);
        }
        // A direct leaf-category link always defines its own navigation group.
        // Stale URL/form parameters must not mislabel results with another parent.
        if (isset($filters['category'])) {
            unset($filters['group']);
        }

        // Location is a browsing scope, not a search term. A location-only
        // visit still starts with main categories; search, direct legacy leaf
        // links and explicit "View all" show listing results immediately.
        $isDiscovery = ! $request->is('api/*')
            && ! isset($filters['category']) && ! isset($filters['view'])
            && ! array_intersect_key($filters, array_flip(['q', 'condition', 'min_price', 'max_price', 'sort']));
        $selectedGroup = $filters['group'] ?? null;
        $query = $this->marketplace->visible($site)
            ->when($selectedGroup !== null && ! isset($filters['category']),
                fn ($query) => $query->whereIn('category', MarketplaceCategories::childSlugs($selectedGroup, $categories->all())))
            ->when(isset($filters['q']), fn ($query) => KeywordSearch::apply($query, $filters['q'], ['title', 'description']))
            ->when($filters['category'] ?? null, fn ($query, $value) => $query->where('category', $value))
            ->when($filters['condition'] ?? null, fn ($query, $value) => $query->where('condition', $value))
            ->when(isset($filters['min_price']), fn ($query) => $query->where('price_cents', '>=', (int) round((float) $filters['min_price'] * 100)))
            ->when(isset($filters['max_price']), fn ($query) => $query->where('price_cents', '<=', (int) round((float) $filters['max_price'] * 100)))
            ->when(isset($filters['location']), fn ($query) => $query->whereHas('location', fn ($location) => $location->selectable()->where('slug', $filters['location'])));
        if (isset($filters['sort'])) {
            $query->orderBy('price_cents', $filters['sort'] === 'price_asc' ? 'asc' : 'desc');
        }
        // The category chooser needs no listing query or count on large datasets.
        $items = $isDiscovery ? null : $query->latest('published_at')->orderByDesc('id')->paginate(18)->appends($filters);

        if ($request->is('api/*')) {
            return response()->json([
                'data' => $items->map(fn (Listing $listing) => $this->publicData($listing)),
                'meta' => ['page' => $items->currentPage(), 'last_page' => $items->lastPage(), 'total' => $items->total()],
            ]);
        }

        return view('marketplace.index', [
            'items' => $items, 'filters' => $filters, 'categories' => $categories,
            'categoryGroups' => $categoryGroups, 'selectedGroup' => $selectedGroup, 'isDiscovery' => $isDiscovery,
            'sortOptions' => self::SORT_OPTIONS, 'activeFilterCount' => count(array_diff_key($filters, ['sort' => true, 'view' => true, 'group' => true])),
            'locations' => $site->isNetwork() ? Location::selectable()->orderBy('name')->get() : collect(),
        ]);
    }

    public function show(Request $request, int $listing)
    {
        $site = $request->attributes->get('site');
        abort_unless($this->marketplace->enabled($site), 404);
        $item = $this->marketplace->visible($site)->with('seller:id,name,created_at')->whereKey($listing)->firstOrFail();
        $owner = $this->marketplace->canonicalSite($item);
        $canonical = NetworkUrl::to($owner, '/marketplace/'.$item->id);

        // Browsing stays on the visitor's portal; search engines use the single
        // canonical community URL. City portals still enforce their own scope.
        $related = $this->marketplace->visible($site)->whereKeyNot($item->id)
            ->where('category', $item->category)->orderByRaw('CASE WHEN location_id = ? THEN 0 ELSE 1 END', [$item->location_id])
            ->latest('published_at')->limit(4)->get();
        $recent = $this->marketplace->visible($site)->whereKeyNot($item->id)
            ->where('location_id', $item->location_id)->whereNotIn('id', $related->pluck('id'))
            ->latest('published_at')->limit(4)->get();

        $member = $request->user();
        $saved = $member && DB::table('listing_favourites')->where(['user_id' => $member->id, 'listing_id' => $item->id])->exists();
        $conversation = $member && $member->id !== $item->user_id
            ? Conversation::where(['buyer_id' => $member->id, 'listing_id' => $item->id])->first()
            : null;
        $reported = $member && DB::table('listing_reports')->where(['listing_id' => $item->id, 'reporter_id' => $member->id])->exists();
        $messagingBlocked = $member && DB::table('user_blocks')->where(fn ($query) => $query->where(['user_id' => $member->id, 'blocked_id' => $item->user_id])->orWhere(['user_id' => $item->user_id, 'blocked_id' => $member->id]))->exists();
        $sellerListingCount = $this->marketplace->visible($site)->where('user_id', $item->user_id)->count();

        return view('marketplace.show', compact('item', 'canonical', 'related', 'recent', 'saved', 'conversation', 'sellerListingCount', 'reported', 'messagingBlocked'));
    }

    public function create(Request $request)
    {
        return $this->form($request, new Listing);
    }

    public function store(SaveListing $request, ListingPhotos $photos)
    {
        abort_unless($this->marketplace->enabled($request->attributes->get('site')), 404);
        $data = $request->attributesForPersistence();
        $media = $photos->store($request->file('photos', []));
        try {
            $item = DB::transaction(function () use ($request, $data, $media) {
                $item = Listing::create($data + ['user_id' => $request->user()->id, 'status' => 'pending']);
                $item->media()->createMany($media);
                $this->marketplace->history($item, null, $request->user()->id);

                return $item;
            });
        } catch (Throwable $exception) {
            $photos->delete($media);
            throw $exception;
        }
        if ($request->is('api/*')) {
            return response()->json(['data' => $this->publicData($item) + ['status' => $item->status]], 201);
        }

        return redirect('/account/listings')->with('status', 'Your listing was submitted for review.');
    }

    public function mine(Request $request)
    {
        $site = $request->attributes->get('site');

        abort_unless($this->marketplace->enabled($site), 404);
        $statuses = ['active', 'pending', 'expired', 'rejected', 'sold'];
        $filters = $request->validate([
            'status' => ['nullable', Rule::in($statuses)],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $owned = Listing::query()->where('user_id', $request->user()->id)
            ->whereIn('status', $statuses)
            ->when($site->location_id, fn ($query, $id) => $query->where('location_id', $id));
        $counts = (clone $owned)->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
        $items = $owned->with(['location', 'media'])
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['q'] ?? null, fn ($query, $term) => KeywordSearch::apply($query, $term, ['title', 'description']))
            ->latest()->latest('id')->paginate(18)->withQueryString();

        return view('marketplace.mine', compact('items', 'counts', 'statuses', 'filters'));
    }

    public function history(Request $request, int $listing)
    {
        $site = $request->attributes->get('site');
        abort_unless($this->marketplace->enabled($site), 404);
        $item = Listing::with('location')->where('user_id', $request->user()->id)
            ->when($site->location_id, fn ($query, $id) => $query->where('location_id', $id))->findOrFail($listing);
        $history = DB::table('listing_status_history')->where('listing_id', $item->id)
            ->latest('id')->paginate(30, ['id', 'previous_status', 'status', 'created_at']);

        return view('marketplace.history', compact('item', 'history'));
    }

    public function duplicate(Request $request, int $listing)
    {
        $source = $this->owned($request, $listing);
        abort_unless(in_array($source->status, ['sold', 'expired', 'rejected'], true), 409);
        $item = new Listing($source->only(['location_id', 'title', 'description', 'category', 'condition', 'price_cents', 'negotiable', 'attributes']));

        return $this->form($request, $item, $source->location);
    }

    public function edit(Request $request, int $listing)
    {
        return $this->form($request, $this->owned($request, $listing));
    }

    public function update(SaveListing $request, int $listing, ListingPhotos $photos)
    {
        abort_unless($this->marketplace->enabled($request->attributes->get('site')), 404);
        $data = $request->attributesForPersistence();
        $this->owned($request, $listing);
        $media = $photos->store($request->file('photos', []));
        try {
            $removed = DB::transaction(function () use ($request, $listing, $data, $media) {
                $item = $this->owned($request, $listing, true);
                abort_unless(in_array($item->status, ['pending', 'active', 'expired', 'rejected'], true), 409);
                $item->fill($data);
                $removed = [];
                if ($request->boolean('manage_photos')) {
                    if (! hash_equals($item->photoVersion(), (string) $request->validated('photo_version'))) {
                        throw ValidationException::withMessages(['photos' => 'The existing photos changed. Reload the editor before trying again.']);
                    }
                    $current = $item->media()->get()->keyBy('id');
                    $keep = array_map('intval', $request->validated('keep_photos', []));
                    if (array_diff($keep, $current->keys()->all()) || count($keep) + count($media) > 5) {
                        throw ValidationException::withMessages(['photos' => 'Keep only photos from this listing, with at most five photos in total.']);
                    }
                    $discard = $current->except($keep);
                    $removed = $discard->values()->toArray();
                    $item->media()->whereIn('id', $discard->modelKeys())->delete();
                    // Move retained photos out of the occupied position range before
                    // assigning their final order (listing_id, position is unique).
                    foreach ($keep as $position => $id) {
                        $current[$id]->update(['position' => 100 + $position]);
                    }
                    foreach ($keep as $position => $id) {
                        $current[$id]->update(['position' => $position]);
                    }
                    foreach ($media as $position => $photo) {
                        $item->media()->create(array_replace($photo, ['position' => count($keep) + $position]));
                    }
                } elseif ($media !== []) {
                    $removed = $item->media()->get()->toArray();
                    $item->media()->delete();
                    $item->media()->createMany($media);
                }
                $this->marketplace->changeStatus($item, 'pending', $request->user()->id);

                return $removed;
            });
        } catch (Throwable $exception) {
            $photos->delete($media);
            throw $exception;
        }
        $photos->delete($removed);

        return redirect('/account/listings')->with('status', 'Your changes were submitted for review.');
    }

    public function status(Request $request, int $listing)
    {
        abort_unless($this->marketplace->enabled($request->attributes->get('site')), 404);
        $data = $request->validate(['status' => ['required', Rule::in(['removed', 'sold', 'pending'])]]);
        DB::transaction(function () use ($request, $listing, $data) {
            $item = $this->owned($request, $listing, true);
            abort_if($data['status'] === 'sold' && $item->status !== 'active', 409);
            abort_if($data['status'] === 'pending' && $item->status !== 'expired' && ! ($item->status === 'active' && $item->expires_at?->isPast()), 409);
            $this->marketplace->changeStatus($item, $data['status'], $request->user()->id);
        });

        return redirect('/account/listings')->with('status', 'Listing status updated.');
    }

    private function form(Request $request, Listing $item, ?Location $duplicateLocation = null)
    {
        $site = $request->attributes->get('site');
        abort_unless($this->marketplace->enabled($site), 404);

        return view('marketplace.form', [
            'item' => $item, 'categories' => $this->categories(), 'conditions' => Marketplace::CONDITIONS,
            'fields' => Marketplace::FIELDS, 'locations' => $site->isNetwork() ? Location::selectable()->orderBy('name')->get() : collect(),
            'suggestedLocation' => $item->exists ? null : ($duplicateLocation ?? LocationScope::forBrowsing($site, $request->query('location', $request->cookie(LocationScope::PREFERENCE_COOKIE)))),
            'duplicating' => $duplicateLocation !== null,
            'photoVersion' => $item->exists ? $item->photoVersion() : null,
        ]);
    }

    private function owned(Request $request, int $id, bool $lock = false): Listing
    {
        $site = $request->attributes->get('site');

        return Listing::where('user_id', $request->user()->id)->where('status', '!=', 'removed')
            ->when($site->location_id, fn ($query, $id) => $query->where('location_id', $id))
            ->when($lock, fn ($query) => $query->lockForUpdate())->findOrFail($id);
    }

    private function categories()
    {
        return ListingCategory::orderBy('name')->pluck('name', 'slug');
    }

    private function publicData(Listing $listing): array
    {
        return [
            'id' => $listing->id, 'title' => $listing->title, 'description' => $listing->description,
            'category' => $listing->category, 'location' => $listing->location->name,
            'price_cents' => $listing->price_cents, 'negotiable' => $listing->negotiable,
            'condition' => $listing->condition, 'published_at' => $listing->published_at,
        ];
    }
}
