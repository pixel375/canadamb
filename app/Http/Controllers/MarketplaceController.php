<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveListing;
use App\Models\Conversation;
use App\Models\Listing;
use App\Models\ListingCategory;
use App\Models\Location;
use App\Support\Audit;
use App\Support\KeywordSearch;
use App\Support\ListingPhotos;
use App\Support\LocationScope;
use App\Support\Marketplace;
use App\Support\MarketplaceCategories;
use App\Support\MarketplaceSearch;
use App\Support\NetworkUrl;
use App\Support\PublicationPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class MarketplaceController extends Controller
{
    public function __construct(private readonly Marketplace $marketplace) {}

    public function index(Request $request)
    {
        $site = $request->attributes->get('site');
        abort_unless($this->marketplace->enabled($site), 404);
        $categories = $this->categories();
        $categoryGroups = MarketplaceCategories::grouped($categories->all());
        $filters = MarketplaceSearch::filters($request, $site, $categoryGroups);
        // Location is a browsing scope, not a search term. A location-only
        // visit still starts with main categories; search, direct legacy leaf
        // links and explicit "View all" show listing results immediately.
        $isDiscovery = ! $request->is('api/*')
            && ! isset($filters['category']) && ! isset($filters['view'])
            && ! array_intersect_key($filters, array_flip(['q', 'condition', 'min_price', 'max_price', 'sort']));
        $selectedGroup = $filters['group'] ?? null;
        $query = MarketplaceSearch::matching($site, $filters, $categories->all());
        // The category chooser needs no listing query or count on large datasets.
        $items = $isDiscovery ? null : $query->latest('published_at')->orderByDesc('id')->paginate(18)->appends(LocationScope::preserveAll($filters + ['view' => 'all']));

        if ($request->is('api/*')) {
            return response()->json([
                'data' => $items->map(fn (Listing $listing) => $this->publicData($listing)),
                'meta' => ['page' => $items->currentPage(), 'last_page' => $items->lastPage(), 'total' => $items->total()],
            ]);
        }

        return view('marketplace.index', [
            'items' => $items, 'filters' => $filters, 'categories' => $categories,
            'categoryGroups' => $categoryGroups, 'selectedGroup' => $selectedGroup, 'isDiscovery' => $isDiscovery,
            'sortOptions' => MarketplaceSearch::SORT_OPTIONS, 'activeFilterCount' => count(array_diff_key($filters, ['sort' => true, 'view' => true])),
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
        $category = $request->query('category');
        $item = new Listing;
        if (is_string($category) && strlen($category) <= 64 && ListingCategory::whereKey($category)->exists()) {
            $item->category = $category;
        }

        return $this->form($request, $item);
    }

    public function store(SaveListing $request, ListingPhotos $photos)
    {
        abort_unless($this->marketplace->enabled($request->attributes->get('site')), 404);
        $data = $request->attributesForPersistence();
        $filters = $request->is('api/*') ? [] : $this->sellerReturnFilters($request);
        $media = $photos->store($request->file('photos', []));
        try {
            $item = DB::transaction(function () use ($request, $data, $media) {
                $item = Listing::create($data + ['user_id' => $request->user()->id, 'status' => 'pending']);
                $item->media()->createMany($media);
                $this->marketplace->history($item, null, $request->user()->id);
                if (PublicationPolicy::allows('listings', $request->user(), $item->location_id)) {
                    $this->marketplace->changeStatus($item, 'active', null, 'Published by configured automatic approval policy.');
                    Audit::record('marketplace.listing.auto-approved', null, context: ['listing' => $item->id]);
                }

                return $item;
            });
        } catch (Throwable $exception) {
            $photos->delete($media);
            throw $exception;
        }
        if ($request->is('api/*')) {
            return response()->json(['data' => $this->publicData($item) + ['status' => $item->status]], 201);
        }

        return redirect($this->sellerReturnPath($filters))->with('status', $item->status === 'active' ? 'Your listing was published.' : 'Your listing was submitted for review.');
    }

    public function mine(Request $request)
    {
        $site = $request->attributes->get('site');

        abort_unless($this->marketplace->enabled($site), 404);
        $statuses = ['active', 'pending', 'expired', 'rejected', 'sold'];
        $filters = $request->validate($this->sellerFilterRules());
        if ($site->location_id !== null && isset($filters['location']) && (int) $filters['location'] !== $site->location_id) {
            throw ValidationException::withMessages(['location' => 'Use CanadaMB to manage listings in another community.']);
        }
        $owned = Listing::query()->where('user_id', $request->user()->id)
            ->whereIn('status', $statuses)
            ->when($site->location_id, fn ($query, $id) => $query->where('location_id', $id));
        $places = $site->isNetwork() ? Location::whereIn('id', (clone $owned)->select('location_id'))->orderBy('name')->get(['id', 'name']) : collect();
        $categories = $this->categories();
        $matching = $owned->when($filters['location'] ?? null, fn ($query, $id) => $query->where('location_id', $id))
            ->when($filters['category'] ?? null, fn ($query, $category) => $query->where('category', $category))
            ->when($filters['reference'] ?? null, fn ($query, $id) => $query->whereKey($id))
            ->when(filled($filters['q'] ?? null), fn ($query) => KeywordSearch::apply($query, $filters['q'], ['title', 'description']));
        if (($filters['expiry'] ?? null) === 'soon') {
            $matching->where('status', 'active')->where('expires_at', '>', now())->where('expires_at', '<=', now()->addDays(7));
        } elseif (($filters['expiry'] ?? null) === 'ended') {
            $matching->where(fn ($query) => $query->where('status', 'expired')
                ->orWhere(fn ($query) => $query->where('status', 'active')->where('expires_at', '<=', now())));
        }
        $counts = (clone $matching)->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
        $query = $matching->with(['location', 'media', 'categoryRecord'])
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status));
        match ($filters['sort'] ?? 'newest') {
            'oldest' => $query->oldest()->orderBy('id'),
            'price-low' => $query->orderBy('price_cents')->latest('id'),
            'price-high' => $query->orderByDesc('price_cents')->latest('id'),
            'expiry' => $query->orderByRaw('CASE WHEN expires_at IS NULL THEN 1 ELSE 0 END')->orderBy('expires_at')->latest('id'),
            default => $query->latest()->latest('id'),
        };
        $items = $query->paginate(18)->appends($filters);

        return view('marketplace.mine', compact('items', 'counts', 'statuses', 'filters', 'places', 'categories'));
    }

    public function history(Request $request, int $listing)
    {
        $site = $request->attributes->get('site');
        abort_unless($this->marketplace->enabled($site), 404);
        $item = Listing::with('location')->where('user_id', $request->user()->id)
            ->when($site->location_id, fn ($query, $id) => $query->where('location_id', $id))->findOrFail($listing);
        $filters = $this->sellerReturnFilters($request);
        $returnUrl = $this->sellerReturnPath($filters);
        $history = DB::table('listing_status_history')->where('listing_id', $item->id)
            ->latest('id')->paginate(30, ['id', 'previous_status', 'status', 'created_at'])->appends(['return_filters' => $filters]);

        return view('marketplace.history', compact('item', 'history', 'returnUrl'));
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
        $item = $this->owned($request, $listing);
        abort_unless(in_array($item->status, ['pending', 'active', 'expired', 'rejected'], true), 409);

        return $this->form($request, $item);
    }

    public function update(SaveListing $request, int $listing, ListingPhotos $photos)
    {
        abort_unless($this->marketplace->enabled($request->attributes->get('site')), 404);
        $data = $request->attributesForPersistence();
        $this->owned($request, $listing);
        $filters = $this->sellerReturnFilters($request);
        $media = $photos->store($request->file('photos', []));
        try {
            $removed = DB::transaction(function () use ($request, $listing, $data, $media) {
                $item = $this->owned($request, $listing, true);
                abort_unless(in_array($item->status, ['pending', 'active', 'expired', 'rejected'], true), 409);
                abort_unless(hash_equals($item->reviewVersion(), $request->validated('version')), 409, 'Listing changed. Reload before editing.');
                $item->fill($data);
                $changed = $item->isDirty() || $media !== [];
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
                    $changed = $changed || $keep !== $current->keys()->all();
                    $discard = $current->except($keep);
                    $removed = $discard->values()->toArray();
                    $item->media()->whereIn('id', $discard->modelKeys())->delete();
                    // Move retained photos only when their order or positions change.
                    if ($keep !== $current->keys()->all()) {
                        foreach ($keep as $position => $id) {
                            $current[$id]->update(['position' => 100 + $position]);
                        }
                        foreach ($keep as $position => $id) {
                            $current[$id]->update(['position' => $position]);
                        }
                    }
                    foreach ($media as $position => $photo) {
                        $item->media()->create(array_replace($photo, ['position' => count($keep) + $position]));
                    }
                } elseif ($media !== []) {
                    $removed = $item->media()->get()->toArray();
                    $item->media()->delete();
                    $item->media()->createMany($media);
                }
                if ($changed || in_array($item->status, ['expired', 'rejected'], true)) {
                    $this->marketplace->changeStatus($item, 'pending', $request->user()->id);
                }

                return $removed;
            });
        } catch (Throwable $exception) {
            $photos->delete($media);
            throw $exception;
        }
        $photos->delete($removed);

        return redirect($this->sellerReturnPath($filters))->with('status', 'Listing saved. Changed content requires review.');
    }

    public function status(Request $request, int $listing)
    {
        abort_unless($this->marketplace->enabled($request->attributes->get('site')), 404);
        $filters = DB::transaction(function () use ($request, $listing) {
            $item = $this->owned($request, $listing, true);
            $data = $request->validate(['status' => ['required', Rule::in(['removed', 'sold', 'pending'])], 'version' => 'required|string|size:64']);
            abort_unless(hash_equals($item->reviewVersion(), $data['version']), 409, 'Listing changed. Reload before changing status.');
            abort_unless($item->allowsSellerStatus($data['status']), 409);
            $filters = $this->sellerReturnFilters($request);
            $this->marketplace->changeStatus($item, $data['status'], $request->user()->id);

            return $filters;
        });

        return redirect($this->sellerReturnPath($filters))->with('status', 'Listing status updated.');
    }

    public function batchStatus(Request $request)
    {
        $site = $request->attributes->get('site');
        abort_unless($this->marketplace->enabled($site), 404);
        $data = $request->validate([
            'status' => 'required|in:sold,pending',
            'listings' => 'required|array|list|min:1|max:10',
            'listings.*' => 'required|integer|distinct|min:1',
            'versions' => 'required|array|max:18', 'versions.*' => 'required|string|size:64',
            'confirm_batch' => 'accepted',
        ]);
        $member = $request->user()->id;
        $filters = $this->sellerReturnFilters($request);
        DB::transaction(function () use ($data, $member, $site) {
            $items = Listing::where('user_id', $member)->where('status', '!=', 'removed')
                ->when($site->location_id, fn ($query, $id) => $query->where('location_id', $id))
                ->whereIn('id', $data['listings'])->orderBy('id')->lockForUpdate()->get();
            abort_unless($items->count() === count($data['listings']), 404);
            foreach ($items as $item) {
                if (! isset($data['versions'][$item->id]) || ! hash_equals($item->reviewVersion(), $data['versions'][$item->id])) {
                    throw ValidationException::withMessages(['listings' => 'A selected listing changed. Reload and select again.']);
                }
                if (! $item->allowsSellerStatus($data['status'])) {
                    throw ValidationException::withMessages(['listings' => 'All selected listings must be eligible for the requested action. No listings changed.']);
                }
            }
            foreach ($items as $item) {
                $this->marketplace->changeStatus($item, $data['status'], $member);
            }
        }, attempts: 3);

        return redirect($this->sellerReturnPath($filters))->with('status', count($data['listings']).' listing statuses updated.');
    }

    private function sellerFilterRules(): array
    {
        return [
            'status' => 'nullable|in:active,pending,expired,rejected,sold',
            'q' => ['nullable', 'string', 'max:100'],
            'location' => ['nullable', 'integer', 'min:1'],
            'category' => ['nullable', 'string', 'exists:listing_categories,slug'],
            'reference' => ['nullable', 'integer', 'min:1'],
            'expiry' => ['nullable', 'in:soon,ended'],
            'sort' => ['nullable', 'in:newest,oldest,price-low,price-high,expiry'],
        ];
    }

    private function sellerReturnFilters(Request $request): array
    {
        $rules = ['return_filters' => 'nullable|array'];
        foreach ($this->sellerFilterRules() as $key => $rule) {
            $rules['return_filters.'.$key] = $rule;
        }
        $data = $request->validate($rules);
        $filters = array_intersect_key($data['return_filters'] ?? [], $this->sellerFilterRules());
        $site = $request->attributes->get('site');
        if ($site->location_id !== null && isset($filters['location']) && (int) $filters['location'] !== $site->location_id) {
            throw ValidationException::withMessages(['return_filters.location' => 'Use CanadaMB to manage listings in another community.']);
        }

        return array_filter($filters, fn ($value) => $value !== null && $value !== '');
    }

    private function sellerReturnPath(array $filters): string
    {
        $query = http_build_query($filters, '', '&', PHP_QUERY_RFC3986);

        return '/account/listings'.($query === '' ? '' : '?'.$query);
    }

    private function form(Request $request, Listing $item, ?Location $duplicateLocation = null)
    {
        $site = $request->attributes->get('site');
        abort_unless($this->marketplace->enabled($site), 404);

        $returnFilters = $this->sellerReturnFilters($request);

        return view('marketplace.form', [
            'returnFilters' => $returnFilters, 'returnUrl' => $this->sellerReturnPath($returnFilters),
            'item' => $item, 'categories' => $this->categories(), 'conditions' => Marketplace::CONDITIONS,
            'fields' => Marketplace::FIELDS, 'locations' => $site->isNetwork() ? Location::selectable()->orderBy('name')->get() : collect(),
            'suggestedLocation' => $item->exists ? null : ($duplicateLocation ?? LocationScope::currentBrowsing($site)),
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
