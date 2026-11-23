<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveListing;
use App\Models\Listing;
use App\Models\ListingCategory;
use App\Models\Location;
use App\Support\ApprovalPolicy;
use App\Support\ListingPhotoOrder;
use App\Support\ListingPhotos;
use App\Support\Marketplace;
use App\Support\MarketplaceCategories;
use App\Support\NetworkUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
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
            ->when(isset($filters['q']), function ($query) use ($filters) {
                $term = '%'.strtr($filters['q'], ['!' => '!!', '%' => '!%', '_' => '!_']).'%';

                return $query->where(fn ($match) => $match->whereRaw("title LIKE ? ESCAPE '!'", [$term])
                    ->orWhereRaw("description LIKE ? ESCAPE '!'", [$term]));
            })
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
            ->where('category', $item->category)->latest('published_at')->latest('id')->limit(2)->get();
        $recent = $this->marketplace->visible($site)->whereKeyNot($item->id)
            ->whereNotIn('id', $related->pluck('id'))
            ->latest('published_at')->latest('id')->limit(2)->get();
        $viewer = $request->user();
        $canEngage = $viewer?->hasVerifiedEmail() && $viewer->id !== $item->user_id;
        $isSaved = $canEngage && DB::table('listing_favourites')
            ->where(['listing_id' => $item->id, 'user_id' => $viewer->id])->exists();
        $hasReported = $canEngage && DB::table('listing_reports')
            ->where(['listing_id' => $item->id, 'reporter_id' => $viewer->id])->exists();

        return view('marketplace.show', compact('item', 'canonical', 'related', 'recent', 'isSaved', 'hasReported'));
    }

    public function contact(Request $request, int $listing)
    {
        $site = $request->attributes->get('site');
        abort_unless($this->marketplace->enabled($site), 404);
        $item = $this->marketplace->visible($site)->without(['location', 'categoryRecord', 'media'])->findOrFail($listing, ['id', 'user_id']);
        $anchor = $item->user_id === $request->user()->id ? '#seller' : '#message-seller';

        return redirect('/marketplace/'.$item->id.$anchor, 302, ['Cache-Control' => 'private, no-store']);
    }

    public function create(Request $request)
    {
        return $this->form($request, new Listing);
    }

    public function store(SaveListing $request, ListingPhotos $photos, ApprovalPolicy $policy)
    {
        $site = $request->attributes->get('site');
        abort_unless($this->marketplace->enabled($site), 404);
        $data = $request->attributesForPersistence();
        $files = $request->file('photos', []);
        $order = ListingPhotoOrder::references(new Listing, $request->photoPlan(), $files);
        $media = $photos->store($files);
        try {
            $item = DB::transaction(function () use ($request, $data, $order, $media, $policy, $site) {
                $author = $request->user()->id;
                $item = Listing::create($data + ['user_id' => $author, 'status' => 'pending']);
                ListingPhotoOrder::save($item, $order, $media);
                $this->marketplace->history($item, null, $author);
                $revision = $media === [] ? $policy->automaticRevision($site, 'listing', $item->location_id) : null;
                if ($revision !== null) {
                    $this->marketplace->changeStatus($item, 'active', null, moderated: true);
                    $policy->recordAutomaticApproval('listing', $item->id, $author, $site, $revision, $item->location_id);
                }

                return $item;
            }, attempts: 3);
        } catch (Throwable $exception) {
            $photos->delete($media);
            throw $exception;
        }
        if ($request->is('api/*')) {
            return response()->json(['data' => $this->publicData($item) + ['status' => $item->status]], 201);
        }

        return redirect('/account/listings')->with('status', $item->status === 'active' ? 'Your listing was published.' : 'Your listing was submitted for review.');
    }

    public function mine(Request $request)
    {
        $site = $request->attributes->get('site');
        $marketplaceEnabled = $this->marketplace->enabled($site);

        $items = $marketplaceEnabled ? Listing::with('location')
            ->addSelect(['moderation_reason' => DB::table('listing_status_history')->select('reason')
                ->whereColumn('listing_id', 'listings.id')->whereColumn('status', 'listings.status')
                ->whereIn('status', ['rejected', 'removed'])->latest('id')->limit(1)])
            ->where('user_id', $request->user()->id)
            ->when($site->location_id !== null, fn ($query) => $query->where('location_id', $site->location_id))
            ->latest('updated_at')->latest('id')->paginate(18)
            ->through(fn (Listing $item) => $item->setAttribute('seller_status', $this->sellerStatus($item))) : null;

        return view('marketplace.mine', compact('items', 'marketplaceEnabled'));
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
        $requested = $request->photoPlan();
        $version = $request->validated('photo_version');
        $files = $request->file('photos', []);
        $item = $this->owned($request, $listing);
        $this->assertEditableVersion($item, $version);
        $order = ListingPhotoOrder::references($item, $requested, $files);
        $media = $photos->store($files);
        try {
            $changed = DB::transaction(function () use ($request, $listing, $data, $order, $version, $requested, $media, $photos) {
                $item = $this->owned($request, $listing, true);
                $this->assertEditableVersion($item, $version);
                // Legacy forms with no uploads keep the latest set even if an older client submitted the edit.
                if ($requested === null && $media === []) {
                    $order = ListingPhotoOrder::existing($item);
                }
                $item->fill($data);
                $photosChanged = ListingPhotoOrder::existing($item) !== $order;
                if (! $item->isDirty() && ! $photosChanged) {
                    return false;
                }
                if ($item->status === 'active' && $item->expires_at !== null && $item->expires_at <= now()) {
                    $item->discardChanges();
                    $this->marketplace->changeStatus($item, 'expired', null);
                    $item->fill($data);
                }
                if ($photosChanged) {
                    $removed = ListingPhotoOrder::save($item, $order, $media);
                    DB::afterCommit(fn () => $photos->delete($removed, unreferenced: true));
                }
                if ($item->status === 'pending') {
                    $item->save();
                } else {
                    $this->marketplace->changeStatus($item, 'pending', $request->user()->id);
                }

                return true;
            });
        } catch (Throwable $exception) {
            $photos->delete($media);
            throw $exception;
        }

        return redirect('/account/listings')->with('status', $changed ? 'Your changes were submitted for review.' : 'No changes were made.');
    }

    public function status(Request $request, int $listing)
    {
        abort_unless($this->marketplace->enabled($request->attributes->get('site')), 404);
        $data = $request->validate(['status' => ['required', Rule::in(['removed', 'sold', 'pending'])]]);
        DB::transaction(function () use ($request, $listing, $data) {
            $item = $this->owned($request, $listing, true);
            $current = $this->sellerStatus($item);
            abort_if($data['status'] === 'sold' && $current !== 'active', 409);
            abort_if($data['status'] === 'pending' && ! in_array($current, ['expired', 'sold'], true), 409);
            if ($data['status'] === 'pending' && $current === 'expired' && $item->status === 'active' && $item->expires_at !== null) {
                $this->marketplace->changeStatus($item, 'expired', null);
            }
            $this->marketplace->changeStatus($item, $data['status'], $request->user()->id);
        });

        $message = match ($data['status']) {
            'pending' => 'Listing submitted for review before relisting.',
            'sold' => 'Listing marked as sold.',
            'removed' => 'Listing removed from public view.',
        };

        return redirect('/account/listings')->with('status', $message);
    }

    private function sellerStatus(Listing $item): string
    {
        if ($item->status === 'active' && ($item->expires_at === null || $item->expires_at <= now())) {
            return 'expired';
        }

        return $item->status;
    }

    private function form(Request $request, Listing $item)
    {
        $site = $request->attributes->get('site');
        abort_unless($this->marketplace->enabled($site), 404);

        return view('marketplace.form', [
            'item' => $item, 'categories' => $this->categories(), 'conditions' => Marketplace::CONDITIONS,
            'fields' => Marketplace::FIELDS, 'locations' => $site->isNetwork() ? Location::selectable()->orderBy('name')->get() : collect(),
            'photoVersion' => $item->exists ? ListingPhotoOrder::version($item) : null,
        ]);
    }

    private function assertEditableVersion(Listing $item, string $version): void
    {
        abort_unless(in_array($item->status, ['pending', 'active', 'expired', 'rejected'], true), 409);
        abort_if(! hash_equals(ListingPhotoOrder::version($item), $version), 409, 'This listing changed. Reload the edit form and review the latest version.');
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
