<?php

namespace App\Http\Controllers;

use App\Models\Location;
use App\Models\PropertyListing;
use App\Support\ListingPhotos;
use App\Support\LocationScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Throwable;

class PropertyDraftController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'transaction' => 'nullable|in:sale,rent', 'property_type' => ['nullable', Rule::in(array_keys(PropertyListing::TYPES))],
            'q' => 'nullable|string|max:100',
        ]);
        $items = $this->owned($request)
            ->when($filters['transaction'] ?? null, fn (Builder $query, string $value) => $query->where('transaction', $value))
            ->when($filters['property_type'] ?? null, fn (Builder $query, string $value) => $query->where('property_type', $value))
            ->when($filters['q'] ?? null, fn (Builder $query, string $value) => $query->whereLike('title', '%'.$value.'%'))
            ->latest('id')->paginate(20)->withQueryString();

        return view('properties.index', compact('items', 'filters'));
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

    public function store(Request $request, ListingPhotos $photos)
    {
        abort_unless(config('network.property_drafts'), 404);
        $data = $this->draftData($request);
        $paths = $photos->store($request->file('photos', []), 'property-drafts', 'local');
        try {
            $item = DB::transaction(function () use ($request, $data, $paths) {
                $item = PropertyListing::create($data + ['owner_id' => $request->user()->id]);
                $item->photos()->createMany($paths);

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
        $paths = $photos->store($request->file('photos', []), 'property-drafts', 'local');
        try {
            $replaced = DB::transaction(function () use ($request, $property, $data, $paths) {
                $item = $this->owned($request)->lockForUpdate()->findOrFail($property);
                abort_unless(in_array($item->status, ['draft', 'changes_requested'], true), 409);
                $item->update($data);
                // Material changes invalidate the previous self-attestation and review.
                $item->forceFill([
                    'authorization_attested_at' => null,
                    'authorization_capacity' => null,
                    'review_checklist_completed_at' => null,
                    'publication_expires_at' => null,
                ])->save();
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
        $data = $request->validate([
            'authorization_capacity' => 'required|in:owner,authorized-agent',
            'advertising_consent' => 'required|accepted',
        ]);
        DB::transaction(function () use ($request, $property, $data) {
            $item = $this->owned($request)->lockForUpdate()->findOrFail($property);
            abort_unless(in_array($item->status, ['draft', 'changes_requested'], true), 409);
            abort_if(in_array($item->seller_type, ['agent', 'brokerage'], true) && blank($item->brokerage_name),
                409, 'Enter the brokerage name before submitting this draft.');
            $item->forceFill([
                'status' => 'submitted',
                'review_note' => null,
                'authorization_capacity' => $data['authorization_capacity'],
                'authorization_attested_at' => now(),
                'review_checklist_completed_at' => null,
                'publication_expires_at' => null,
                'withdrawn_at' => null,
            ])->save();
        });

        return redirect('/account/properties/'.$property)->with('status', 'Property authority attested. Draft submitted for private review only.');
    }

    public function archive(Request $request, int $property, ListingPhotos $photos)
    {
        $paths = DB::transaction(function () use ($request, $property) {
            $item = $this->owned($request)->lockForUpdate()->findOrFail($property);
            $old = $item->photos()->get(['image_path', 'thumbnail_path'])->toArray();
            $item->photos()->delete();
            $item->forceFill([
                'status' => 'archived',
                'withdrawn_at' => now(),
                'review_checklist_completed_at' => null,
                'publication_expires_at' => null,
            ])->save();

            return $old;
        });
        $photos->delete($paths, 'local');

        return redirect('/account/properties')->with('status', 'Private property draft archived.');
    }

    public function media(Request $request, int $property, int $photo)
    {
        $item = $this->owned($request)->findOrFail($property);

        return self::privatePhoto($item, $photo);
    }

    public static function privatePhoto(PropertyListing $item, int $id)
    {
        $photo = $item->photos()->findOrFail($id);
        $disk = Storage::disk('local');
        abort_unless($disk->exists($photo->thumbnail_path), 404);

        return $disk->response($photo->thumbnail_path, 'property-photo.webp', ['Content-Type' => 'image/webp', 'Cache-Control' => 'private, no-store']);
    }

    private function owned(Request $request): Builder
    {
        abort_unless(config('network.property_drafts'), 404);
        $site = $request->attributes->get('site');

        return PropertyListing::with('location')->where('owner_id', $request->user()->id)->where('status', '!=', 'archived')
            ->when($site->location_id, fn (Builder $query, int $location) => $query->where('location_id', $location));
    }

    private function form(Request $request, PropertyListing $item)
    {
        $site = $request->attributes->get('site');

        return view('properties.form', ['item' => $item, 'places' => $site->isNetwork() ? Location::selectable()->orderBy('name')->get() : collect()]);
    }

    private function draftData(Request $request): array
    {
        $data = $request->validate([
            'location_id' => 'nullable|integer',
            'title' => 'required|string|max:140', 'description' => 'required|string|min:20|max:6000',
            'transaction' => 'required|in:sale,rent',
            'brokerage_name' => 'nullable|string|max:150',
            'registration_reference' => 'nullable|string|max:100',
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
