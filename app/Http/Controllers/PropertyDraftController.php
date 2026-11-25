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
        ]);
        $statuses = ['draft', 'submitted', 'changes_requested', 'reviewed'];
        $matching = $this->owned($request)->whereIn('status', $statuses)
            ->when($filters['transaction'] ?? null, fn (Builder $query, string $value) => $query->where('transaction', $value))
            ->when($filters['property_type'] ?? null, fn (Builder $query, string $value) => $query->where('property_type', $value))
            ->when($filters['q'] ?? null, fn (Builder $query, string $value) => $query->where(fn (Builder $match) => KeywordSearch::apply($match, $value, ['title', 'description'])));
        $counts = (clone $matching)->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
        $items = $matching->with('photos')
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->latest('id')->paginate(20)->withQueryString();

        return view('properties.index', compact('items', 'filters', 'statuses', 'counts'));
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
                $item->update($data);
                Audit::record('property-draft.revised', $request->user()->id, context: ['property' => $item->id]);
                if ($request->boolean('manage_photos')) {
                    $current = $item->photos()->get()->keyBy('id');
                    $keep = array_map('intval', $selection['keep_photos'] ?? []);
                    if (array_diff($keep, $current->keys()->all()) || count($keep) + count($paths) > 6) {
                        throw ValidationException::withMessages(['photos' => 'Keep only photos from this draft, with at most six photos total.']);
                    }
                    $discard = $current->except($keep);
                    $item->photos()->whereIn('id', $discard->modelKeys())->delete();
                    foreach ($keep as $position => $id) {
                        $current[$id]->update(['position' => 100 + $position]);
                    }
                    foreach ($keep as $position => $id) {
                        $current[$id]->update(['position' => $position]);
                    }
                    foreach ($paths as $position => $photo) {
                        $item->photos()->create(array_replace($photo, ['position' => count($keep) + $position]));
                    }

                    return $discard->values()->toArray();
                }
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
