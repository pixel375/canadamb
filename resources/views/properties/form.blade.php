<x-layouts.site :title="$item->exists ? 'Edit private property draft' : 'New private property draft'">
    <div class="container page-shell narrow">
        <div class="page-intro"><div><h1>{{ $item->exists ? 'Edit private property draft' : 'New private property draft' }}</h1><p>Only you and authorized reviewers can access a submitted draft.</p></div></div>
        <form method="post" enctype="multipart/form-data" action="/account/properties{{ $item->exists ? '/'.$item->id : '' }}" class="panel form-stack">@csrf
            @if($item->exists) @method('PUT') @endif
            <label>Title<input name="title" required maxlength="140" value="{{ old('title', $item->title) }}"></label>
            <div class="form-grid">
                <label>Transaction<select name="transaction" required><option value="">Choose a transaction</option><option value="sale" @selected(old('transaction', $item->transaction) === 'sale')>For sale</option><option value="rent" @selected(old('transaction', $item->transaction) === 'rent')>For rent</option></select></label>
                <label>Property type<select name="property_type" required><option value="">Choose a property type</option>@foreach(\App\Models\PropertyListing::TYPES as $id => $label)<option value="{{ $id }}" @selected(old('property_type', $item->property_type) === $id)>{{ $label }}</option>@endforeach</select></label>
                <label>Seller role<select name="seller_type" required><option value="">Choose a role</option>@foreach(\App\Models\PropertyListing::SELLERS as $id => $label)<option value="{{ $id }}" @selected(old('seller_type', $item->seller_type) === $id)>{{ $label }}</option>@endforeach</select></label>
            </div>
            <div class="property-private-notice"><strong>Seller and brokerage information</strong><p>Private information collected for future manual advertising review. Agents and brokerages must provide their brokerage name before requesting review.</p>
                <div class="form-grid">
                    <label>Brokerage name (if applicable)<input name="brokerage_name" maxlength="150" value="{{ old('brokerage_name', $item->brokerage_name) }}"></label>
                    <label>Registration or brokerage reference (optional)<input name="registration_reference" maxlength="100" value="{{ old('registration_reference', $item->registration_reference) }}"></label>
                </div>
            </div>
            @if(request()->attributes->get('site')->isNetwork())
                <label>Location<select name="location_id" required><option value="">Choose a Manitoba community</option>@foreach($places as $place)<option value="{{ $place->id }}" @selected((string) old('location_id', $item->location_id) === (string) $place->id)>{{ $place->name }}</option>@endforeach</select></label>
            @else <p>Location · {{ request()->attributes->get('site')->location->name }}</p> @endif
            <label>Price or monthly rent (CAD)<input name="price" type="number" min="0.01" max="1000000000" step="0.01" required value="{{ old('price', $item->price_cents === null ? '' : $item->price_cents / 100) }}"></label>
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
            <label>Private street address<input name="private_address" maxlength="240" value="{{ old('private_address', $item->private_address) }}"></label>
            <label>Private legal land description<input name="legal_land_description" maxlength="240" value="{{ old('legal_land_description', $item->legal_land_description) }}"></label>
            <label>Description<textarea name="description" required minlength="20" maxlength="6000" rows="6">{{ old('description', $item->description) }}</textarea></label>
            <label>{{ $item->exists ? 'Replace photos' : 'Property photos' }}<input type="file" name="photos[]" multiple accept="image/jpeg,image/png,image/webp"><small class="field-note">Up to six JPEG, PNG, or WebP images, 5 MB each.</small></label>
            <div><button class="button button-primary">Save private draft</button></div>
        </form>
    </div>
</x-layouts.site>
