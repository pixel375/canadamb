<x-layouts.site :title="$item->exists ? 'Edit private property draft' : 'New private property draft'">
    <div class="container page-shell listing-editor">
        <div class="page-intro"><div><a class="text-link" href="/account/properties">Private property drafts</a><h1>{{ $item->exists ? 'Edit private property draft' : 'New private property draft' }}</h1><p>Only you and authorized reviewers can access a submitted draft.</p></div></div>
        @if($duplicating ?? false)<p class="notice">Copied property details only. Private address, legal land description, availability date, photos and review declarations are not copied. Review the information before saving a new private draft.</p>@endif
        <form method="post" enctype="multipart/form-data" action="/account/properties{{ $item->exists ? '/'.$item->id : '' }}" class="form-stack">@csrf
            @if($item->exists)
                @method('PUT')
                <input type="hidden" name="version" value="{{ old('version', $item->contentVersion()) }}">
            @endif
            <section class="panel form-stack listing-editor-section"><h2>1. Property overview</h2>
            <label>Title<input name="title" required maxlength="140" value="{{ old('title', $item->title) }}"></label>
            <div class="form-grid">
                <label>Transaction<select name="transaction" required><option value="">Choose a transaction</option><option value="sale" @selected(old('transaction', $item->transaction ?? $suggestedTransaction) === 'sale')>For sale</option><option value="rent" @selected(old('transaction', $item->transaction ?? $suggestedTransaction) === 'rent')>For rent</option></select></label>
                <label>Property type<select name="property_type" required><option value="">Choose a property type</option>@foreach(\App\Models\PropertyListing::TYPES as $id => $label)<option value="{{ $id }}" @selected(old('property_type', $item->property_type) === $id)>{{ $label }}</option>@endforeach</select></label>
                <label>Seller role<select name="seller_type" required><option value="">Choose a role</option>@foreach(\App\Models\PropertyListing::SELLERS as $id => $label)<option value="{{ $id }}" @selected(old('seller_type', $item->seller_type) === $id)>{{ $label }}</option>@endforeach</select></label>
            </div>
            @if(request()->attributes->get('site')->isNetwork())
                <label>Location<select name="location_id" required><option value="">Choose a Manitoba community</option>@foreach($places as $place)<option value="{{ $place->id }}" @selected((string) old('location_id', $item->location_id ?? $suggestedLocation?->id) === (string) $place->id)>{{ $place->name }}</option>@endforeach</select></label>
            @else <p>Location · {{ request()->attributes->get('site')->location->name }}</p> @endif
            <label>Price or monthly rent (CAD)<input name="price" type="number" min="0.01" max="1000000000" step="0.01" required value="{{ old('price', $item->price_cents === null ? '' : $item->price_cents / 100) }}"></label>
            </section>
            <section class="panel form-stack listing-editor-section"><h2>2. Property features</h2>
            <div class="form-grid">
                <label>Bedrooms<input name="bedrooms" type="number" min="0" max="100" value="{{ old('bedrooms', $item->bedrooms) }}"></label>
                <label>Bathrooms<input name="bathrooms" type="number" min="0" max="100" step="0.5" value="{{ old('bathrooms', $item->bathrooms) }}"></label>
                <label>Floor area (sq ft)<input name="floor_area" type="number" min="0" max="10000000" value="{{ old('floor_area', $item->floor_area) }}"></label>
                <label>Acreage<input name="acreage" type="number" min="0" max="10000000" step="0.0001" value="{{ old('acreage', $item->acreage) }}"></label>
                <label>Year built<input name="year_built" type="number" min="1600" max="{{ now()->year + 5 }}" value="{{ old('year_built', $item->year_built) }}"></label>
            </div>
            <div class="form-grid">
                <label>Availability date<input name="available_on" type="date" value="{{ old('available_on', $item->available_on?->toDateString()) }}"></label>
                <label>Pets<select name="pets_allowed"><option value="">Unspecified</option><option value="1" @selected(in_array(old('pets_allowed', $item->pets_allowed), [true, '1'], true))>Pets allowed</option><option value="0" @selected(in_array(old('pets_allowed', $item->pets_allowed), [false, '0'], true))>Pets restricted</option></select></label>
            </div>
            </section>
            <section class="panel form-stack listing-editor-section"><h2>3. Private information and description</h2>
            <p class="property-private-notice">Submitting this property for private review requires an authority declaration. Agent and brokerage advertising details will need independent verification before a public property listing can be considered.</p>
            <label>Private street address<input name="private_address" maxlength="240" value="{{ old('private_address', $item->private_address) }}"></label>
            <label>Private legal land description<input name="legal_land_description" maxlength="240" value="{{ old('legal_land_description', $item->legal_land_description) }}"></label>
            <label>Description<textarea data-character-count name="description" required minlength="20" maxlength="6000" rows="6">{{ old('description', $item->description) }}</textarea><small class="field-note" data-character-feedback></small></label>
            </section>
            <section class="panel form-stack listing-editor-section"><h2>4. Private photos</h2>
                <label>{{ $item->exists ? 'Add photos' : 'Property photos' }}<input data-listing-photos data-photo-limit="6" data-photo-mb="5" type="file" name="photos[]" multiple accept="image/jpeg,image/png,image/webp"><small class="field-note">Up to six JPEG, PNG or WebP images, 5 MB each. Photos stay private. Choose a cover or remove selected files before saving.</small></label>
                @if($item->exists && $item->photos->isNotEmpty())
                    <input type="hidden" name="manage_photos" value="1">
                    <h3>Current photos</h3><p class="field-note">Keep, remove or reorder existing photos. New photos are appended after retained photos.</p>
                    <div class="listing-photo-preview" data-existing-photo-grid>
                        @foreach($item->photos as $photo)
                            <figure data-existing-photo="{{ $photo->id }}">
                                <img src="/account/properties/{{ $item->id }}/media/{{ $photo->id }}" alt="Current property photo {{ $loop->iteration }}" loading="lazy">
                                <figcaption data-existing-caption>{{ $loop->first ? 'Cover photo' : 'Photo '.$loop->iteration }}</figcaption>
                                <label class="checkbox"><input type="checkbox" name="keep_photos[]" value="{{ $photo->id }}" checked> Keep photo</label>
                                <div class="listing-photo-actions" data-existing-controls hidden>
                                    <button type="button" class="button button-outline" data-existing-cover>Make cover</button>
                                    <button type="button" class="button button-outline" data-existing-move>Move earlier</button>
                                </div>
                            </figure>
                        @endforeach
                    </div>
                    <p class="field-note" data-existing-feedback role="status"></p>
                @endif
                <p class="field-note" data-photo-feedback role="status" aria-live="polite"></p><div class="listing-photo-preview" data-photo-preview hidden></div>
            </section>
            <div><button class="button button-primary">Save private draft</button></div>
        </form>
    </div>
</x-layouts.site>
