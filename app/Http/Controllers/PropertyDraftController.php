<?php

namespace App\Http\Controllers;

use App\Models\Location;
use App\Models\PropertyListing;
use App\Support\Audit;
use App\Support\KeywordSearch;
use App\Support\ListingPhotos;
use App\Support\LocationScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class PropertyDraftController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'transaction' => 'nullable|in:sale,rent', 'property_type' => ['nullable', Rule::in(array_keys(PropertyListing::TYPES))],
            'q' => 'nullable|string|max:100',
            'status' => 'nullable|in:draft,submitted,changes_requested,reviewed',
            'sort' => 'nullable|in:recent,price-low,price-high',
            'location' => 'nullable|integer|exists:locations,id',
            'min_price' => 'nullable|numeric|decimal:0,2|min:0|max:1000000000',
            'max_price' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:1000000000', Rule::when($request->filled('min_price'), 'gte:min_price')],
        ]);
        $statuses = ['draft', 'submitted', 'changes_requested', 'reviewed'];
        $owned = $this->owned($request);
        $places = Location::whereIn('id', (clone $owned)->select('location_id'))->orderBy('name')->get(['id', 'name']);
        $matching = $owned->whereIn('status', $statuses)
            ->when($filters['location'] ?? null, fn ($query, $id) => $query->where('location_id', $id))
            ->when(isset($filters['min_price']), fn ($query) => $query->where('price_cents', '>=', (int) round($filters['min_price'] * 100)))
            ->when(isset($filters['max_price']), fn ($query) => $query->where('price_cents', '<=', (int) round($filters['max_price'] * 100)))
            ->when($filters['transaction'] ?? null, fn (Builder $query, string $value) => $query->where('transaction', $value))
            ->when($filters['property_type'] ?? null, fn (Builder $query, string $value) => $query->where('property_type', $value))
            ->when(filled($filters['q'] ?? null), fn (Builder $query) => KeywordSearch::apply($query, $filters['q'], ['title', 'description']));
        $counts = (clone $matching)->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
        $items = $matching->with('photos')
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status));
        match ($filters['sort'] ?? 'recent') {
            'price-low' => $items->orderBy('price_cents'),
            'price-high' => $items->orderByDesc('price_cents'),
            default => $items->latest('updated_at'),
        };
        $items = $items->latest('id')->paginate(20)->withQueryString();

        return view('properties.index', compact('items', 'filters', 'statuses', 'counts', 'places'));
    }

    public function history(Request $request)
    {
        abort_unless(config('network.property_drafts'), 404);
        $site = $request->attributes->get('site');
        $filters = $request->validate(['q' => 'nullable|string|max:100', 'reference' => 'nullable|integer|min:1']);
        $items = PropertyListing::where('owner_id', $request->user()->id)->where('status', 'archived')
            ->when($site->location_id, fn ($query, $id) => $query->where('location_id', $id))
            ->when($filters['reference'] ?? null, fn ($query, $id) => $query->whereKey($id))
            ->when(filled($filters['q'] ?? null), fn ($query) => KeywordSearch::apply($query, $filters['q'], ['title']))
            ->with('location:id,name')->latest('updated_at')->latest('id')
            ->paginate(20, ['id', 'title', 'location_id', 'transaction', 'property_type', 'updated_at'])->withQueryString();

        return view('properties.history', compact('items', 'filters'));
    }

    public function show(Request $request, int $property)
    {
        return view('properties.show', ['item' => $this->owned($request)->with('photos')->findOrFail($property), 'review' => false]);
    }

    public function create(Request $request)
    {
        abort_unless(config('network.property_drafts'), 404);

        return $this->form($request, new PropertyListing);
    }

    public function duplicate(Request $request, int $property)
    {
        $source = $this->owned($request)->findOrFail($property);
        $item = new PropertyListing($source->only(['location_id', 'title', 'description', 'transaction', 'property_type',
            'seller_type', 'price_cents', 'bedrooms', 'bathrooms', 'floor_area', 'acreage', 'year_built', 'pets_allowed']));

        return $this->form($request, $item, true);
    }

    public function store(Request $request, ListingPhotos $photos)
    {
        abort_unless(config('network.property_drafts'), 404);
        $data = $this->draftData($request);
        $paths = $photos->store($request->file('photos', []), 'property-drafts', 'local');
        try {
            $item = DB::transaction(function () use ($request, $data, $paths) {
                $item = PropertyListing::create($data + ['owner_id' => $request->user()->id]);
                $item->photos()->createMany($paths);
                Audit::record('property-draft.created', $request->user()->id, context: ['property' => $item->id]);

                return $item;
            });
        } catch (Throwable $exception) {
            $photos->delete($paths, 'local');
            throw $exception;
        }

        return redirect('/account/properties/'.$item->id)->with('status', 'Private property draft saved.');
    }

    public function edit(Request $request, int $property)
    {
        $item = $this->owned($request)->findOrFail($property);
        abort_unless(in_array($item->status, ['draft', 'changes_requested'], true), 409);

        return $this->form($request, $item);
    }

    public function update(Request $request, int $property, ListingPhotos $photos)
    {
        $editable = $this->owned($request)->findOrFail($property);
        abort_unless(in_array($editable->status, ['draft', 'changes_requested'], true), 409);
        $data = $this->draftData($request);
        $selection = $request->validate([
            'version' => 'required|string|size:64',
            'manage_photos' => 'nullable|boolean',
            'keep_photos' => 'nullable|array|list|max:6',
            'keep_photos.*' => 'integer|distinct|min:1',
        ]);
        $paths = $photos->store($request->file('photos', []), 'property-drafts', 'local');
        try {
            $replaced = DB::transaction(function () use ($request, $property, $data, $paths, $selection) {
                $item = $this->owned($request)->lockForUpdate()->findOrFail($property);
                abort_unless(in_array($item->status, ['draft', 'changes_requested'], true), 409);
                abort_unless(hash_equals($item->contentVersion(), $selection['version']), 409, 'Draft changed. Reload the editor.');
                $item->fill($data);
                $changed = $item->isDirty() || $paths !== [];
                if ($request->boolean('manage_photos')) {
                    $current = $item->photos()->get()->keyBy('id');
                    $keep = array_map('intval', $selection['keep_photos'] ?? []);
                    if (array_diff($keep, $current->keys()->all()) || count($keep) + count($paths) > 6) {
                        throw ValidationException::withMessages(['photos' => 'Keep only photos from this draft, with at most six photos total.']);
                    }
                    $changed = $changed || $keep !== $current->keys()->all();
                    $discard = $current->except($keep);
                    if ($discard->isNotEmpty()) {
                        $item->photos()->whereIn('id', $discard->modelKeys())->delete();
                    }
                    if ($keep !== $current->keys()->all()) {
                        foreach ($keep as $position => $id) {
                            $current[$id]->update(['position' => 100 + $position]);
                        }
                        foreach ($keep as $position => $id) {
                            $current[$id]->update(['position' => $position]);
                        }
                    }
                    foreach ($paths as $position => $photo) {
                        $item->photos()->create(array_replace($photo, ['position' => count($keep) + $position]));
                    }

                    $this->saveRevision($item, $request->user()->id, $changed);

                    return $discard->values()->toArray();
                }
                $this->saveRevision($item, $request->user()->id, $changed);
                if ($paths === []) {
                    return [];
                }
                $old = $item->photos()->get(['image_path', 'thumbnail_path'])->toArray();
                $item->photos()->delete();
                $item->photos()->createMany($paths);

                return $old;
            });
        } catch (Throwable $exception) {
            $photos->delete($paths, 'local');
            throw $exception;
        }
        $photos->delete($replaced, 'local');

        return redirect('/account/properties/'.$property)->with('status', 'Private property draft updated.');
    }

    private function saveRevision(PropertyListing $item, int $member, bool $changed): void
    {
        if ($changed) {
            $item->save();
            Audit::record('property-draft.revised', $member, context: ['property' => $item->id]);
        }
    }

    public function submit(Request $request, int $property)
    {
        abort_unless(config('network.property_drafts'), 404);
        $data = $request->validate([
            'version' => 'required|string|size:64',
            'authorization_capacity' => 'required|in:owner,authorized-agent',
            'advertising_consent' => 'required|accepted',
        ]);
        DB::transaction(function () use ($request, $property, $data) {
            $item = $this->owned($request)->lockForUpdate()->findOrFail($property);
            abort_unless(in_array($item->status, ['draft', 'changes_requested'], true), 409);
            abort_unless(hash_equals($item->contentVersion(), $data['version']), 409, 'Draft changed. Reload before submitting.');
            $item->forceFill(['status' => 'submitted', 'review_note' => null])->save();
            // Store declaration in the existing append-only audit log, without
            // placing consent details on any public property record.
            Audit::record('property-draft.authority-declared', $request->user()->id,
                context: ['property' => $item->id, 'capacity' => $data['authorization_capacity'], 'version' => 1, 'content_sha256' => $item->contentVersion()]);
        });

        return redirect('/account/properties/'.$property)->with('status', 'Authority declaration recorded. Submitted for private review only.');
    }

    public function submitSelected(Request $request)
    {
        $data = $request->validate([
            'properties' => 'required|array|list|min:1|max:10', 'properties.*' => 'required|integer|distinct|min:1',
            'versions' => 'required|array|max:20', 'versions.*' => 'required|string|size:64',
            'authorization_capacity' => 'required|in:owner,authorized-agent', 'advertising_consent' => 'required|accepted',
        ]);
        DB::transaction(function () use ($request, $data) {
            $items = $this->owned($request)->whereIn('id', $data['properties'])->orderBy('id')->lockForUpdate()->get();
            abort_unless($items->count() === count($data['properties']), 404);
            foreach ($items as $item) {
                if (! in_array($item->status, ['draft', 'changes_requested'], true)
                    || ! isset($data['versions'][$item->id]) || ! hash_equals($item->contentVersion(), $data['versions'][$item->id])) {
                    throw ValidationException::withMessages(['properties' => 'A selected draft changed or is already in review. No drafts were submitted.']);
                }
            }
            foreach ($items as $item) {
                $item->forceFill(['status' => 'submitted', 'review_note' => null])->save();
                Audit::record('property-draft.authority-declared', $request->user()->id,
                    context: ['property' => $item->id, 'capacity' => $data['authorization_capacity'], 'version' => 1, 'content_sha256' => $item->contentVersion()]);
            }
        }, attempts: 3);

        return redirect('/account/properties')->with('status', count($data['properties']).' properties submitted for private review only.');
    }

    public function withdraw(Request $request, int $property)
    {
        $data = $request->validate(['version' => 'required|string|size:64']);
        DB::transaction(function () use ($request, $property, $data) {
            $item = $this->owned($request)->lockForUpdate()->findOrFail($property);
            abort_unless(in_array($item->status, ['submitted', 'reviewed'], true), 409);
            abort_unless(hash_equals($item->contentVersion(), $data['version']), 409);
            $item->forceFill(['status' => 'draft', 'review_note' => null])->save();
            Audit::record('property-draft.review-withdrawn', $request->user()->id, context: ['property' => $item->id]);
        });

        return redirect('/account/properties/'.$property)->with('status', 'Returned to private draft. Photos retained.');
    }

    public function withdrawSelected(Request $request)
    {
        $data = $request->validate([
            'properties' => 'required|array|list|min:1|max:10', 'properties.*' => 'required|integer|distinct|min:1',
            'versions' => 'required|array|max:20', 'versions.*' => 'required|string|size:64',
            'confirm_withdraw' => 'accepted',
        ]);
        DB::transaction(function () use ($request, $data) {
            $items = $this->owned($request)->whereIn('id', $data['properties'])->orderBy('id')->lockForUpdate()->get();
            abort_unless($items->count() === count($data['properties']), 404);
            foreach ($items as $item) {
                if (! in_array($item->status, ['submitted', 'reviewed'], true)
                    || ! isset($data['versions'][$item->id]) || ! hash_equals($item->contentVersion(), $data['versions'][$item->id])) {
                    throw ValidationException::withMessages(['properties' => 'A selected property changed or is not in review. No drafts changed.']);
                }
            }
            foreach ($items as $item) {
                $item->forceFill(['status' => 'draft', 'review_note' => null])->save();
                Audit::record('property-draft.review-withdrawn', $request->user()->id, context: ['property' => $item->id]);
            }
        }, attempts: 3);

        return redirect('/account/properties')->with('status', count($data['properties']).' properties returned to private drafts. Photos retained.');
    }

    public function archive(Request $request, int $property, ListingPhotos $photos)
    {
        $data = $request->validate(['version' => 'nullable|string|size:64']);
        $paths = DB::transaction(function () use ($request, $property, $data) {
            $item = $this->owned($request)->lockForUpdate()->findOrFail($property);
            if (isset($data['version'])) {
                abort_unless(hash_equals($item->contentVersion(), $data['version']), 409, 'Draft changed. Reload before archiving.');
            }
            abort_unless(in_array($item->status, ['draft', 'changes_requested', 'submitted', 'reviewed'], true), 409);
            $old = $item->photos()->get(['image_path', 'thumbnail_path'])->toArray();
            $item->photos()->delete();
            $item->forceFill(['status' => 'archived'])->save();
            Audit::record('property-draft.withdrawn', $request->user()->id, context: ['property' => $item->id]);

            return $old;
        });
        $photos->delete($paths, 'local');

        return redirect('/account/properties')->with('status', 'Private property draft archived.');
    }

    public function media(Request $request, int $property, int $photo)
    {
        $item = $this->owned($request)->findOrFail($property);

        return self::privatePhoto($item, $photo, $request->query('size') === 'full');
    }

    public static function privatePhoto(PropertyListing $item, int $id, bool $full = false)
    {
        $photo = $item->photos()->findOrFail($id);
        $disk = Storage::disk('local');
        $path = $full ? $photo->image_path : $photo->thumbnail_path;
        $pattern = $full ? '~^property-drafts/[a-f0-9-]{36}\.webp$~D' : '~^property-drafts/[a-f0-9-]{36}-preview\.webp$~D';
        abort_unless(preg_match($pattern, $path) && $disk->exists($path), 404);

        return $disk->response($path, 'property-photo.webp', ['Content-Type' => 'image/webp', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'X-Robots-Tag' => 'noindex']);
    }

    private function owned(Request $request): Builder
    {
        abort_unless(config('network.property_drafts'), 404);
        $site = $request->attributes->get('site');

        return PropertyListing::with('location')->where('owner_id', $request->user()->id)->whereIn('status', ['draft', 'submitted', 'changes_requested', 'reviewed'])
            ->when($site->location_id, fn (Builder $query, int $location) => $query->where('location_id', $location));
    }

    private function form(Request $request, PropertyListing $item, bool $duplicating = false)
    {
        $site = $request->attributes->get('site');

        return view('properties.form', [
            'item' => $item, 'duplicating' => $duplicating,
            'places' => $site->isNetwork() ? Location::selectable()->orderBy('name')->get() : collect(),
            'suggestedLocation' => $item->exists ? null : LocationScope::forBrowsing($site, $request->query('location', $request->cookie(LocationScope::PREFERENCE_COOKIE))),
            'suggestedTransaction' => in_array($request->query('transaction'), ['sale', 'rent'], true) ? $request->query('transaction') : null,
            'suggestedType' => is_string($request->query('property_type')) && isset(PropertyListing::TYPES[$request->query('property_type')]) ? $request->query('property_type') : null,
        ]);
    }

    private function draftData(Request $request): array
    {
        $data = $request->validate([
            'location_id' => 'nullable|integer',
            'title' => 'required|string|max:140', 'description' => 'required|string|min:20|max:6000',
            'transaction' => 'required|in:sale,rent',
            'property_type' => ['required', Rule::in(array_keys(PropertyListing::TYPES))],
            'seller_type' => ['required', Rule::in(array_keys(PropertyListing::SELLERS))],
            'price' => 'required|numeric|decimal:0,2|gt:0|max:1000000000',
            'bedrooms' => 'nullable|integer|min:0|max:100',
            'bathrooms' => 'nullable|numeric|decimal:0,1|multiple_of:0.5|min:0|max:100',
            'floor_area' => 'nullable|integer|min:0|max:10000000',
            'acreage' => 'nullable|numeric|decimal:0,4|min:0|max:10000000',
            'year_built' => 'nullable|integer|min:1600|max:'.(now()->year + 5),
            'private_address' => 'nullable|string|max:240',
            'legal_land_description' => 'nullable|string|max:240',
            'available_on' => 'nullable|date_format:Y-m-d',
            'pets_allowed' => 'nullable|boolean',
            'photos' => 'nullable|array|list|max:6',
            'photos.*' => 'image|mimes:jpeg,jpg,png,webp|max:5120',
        ]);
        $data['location_id'] = LocationScope::forPublication($request->attributes->get('site'), isset($data['location_id']) ? (int) $data['location_id'] : null);
        $data['price_cents'] = (int) round($data['price'] * 100);
        unset($data['price'], $data['photos']);

        return $data;
    }
}
