@php($site = request()->attributes->get('site'))
<x-layouts.site :title="$item->exists ? 'Edit listing' : 'Post a listing'">
    <div class="container page-shell listing-editor">
        <div class="page-intro"><div><a class="text-link" href="/account/listings">My listings</a><h1>{{ $item->exists ? 'Edit listing' : 'Post a listing' }}</h1><p>Basic listings are free and reviewed before publication.</p></div></div>
        @if($duplicating ?? false)<p class="notice">Copied text and item details only. Review the information and upload new photos. This will create a new listing requiring approval; messages, saves and reports stay with the original.</p>@endif
        <form class="listing-editor-form form-stack" method="post" enctype="multipart/form-data" action="{{ $item->exists ? '/account/listings/'.$item->id : '/marketplace' }}" x-data="{ category: @js(old('category', $item->category ?? '')) }">
            @csrf
            @if($item->exists)@method('PUT')<input type="hidden" name="version" value="{{ $item->reviewVersion() }}">@endif
            <section class="panel form-stack listing-editor-section" aria-labelledby="listing-details-heading">
                <h2 id="listing-details-heading">1. Item details</h2>
            <label>Title<input name="title" value="{{ old('title', $item->title) }}" required maxlength="120" placeholder="e.g. Solid oak dining table"></label>
            <label>Description<textarea data-character-count name="description" rows="5" required maxlength="5000" placeholder="Describe your item, its condition and anything a buyer should know.">{{ old('description', $item->description) }}</textarea><small class="field-note" data-character-feedback></small></label>
            <div class="form-grid">
                <label>Category<select name="category" x-model="category" required><option value="">Choose a subcategory</option>
                    @foreach(\App\Support\MarketplaceCategories::grouped($categories->all()) as $group)
                        <optgroup label="{{ $group['name'] }}">
                            @foreach($group['available'] as $key => $label)<option value="{{ $key }}" @selected(old('category', $item->category) === $key)>{{ $label }}</option>@endforeach
                        </optgroup>
                    @endforeach
                </select><small class="field-note">Choose the closest subcategory. Public real estate listings are not yet available.</small></label>
                <label>Condition<select name="condition" required><option value="">Choose a condition</option>@foreach($conditions as $key => $label)<option value="{{ $key }}" @selected(old('condition', $item->condition) === $key)>{{ $label }}</option>@endforeach</select></label>
            </div>
            @foreach($fields as $category => $attributes)
                <fieldset class="form-grid" x-show="category === @js($category)" x-bind:disabled="category !== @js($category)" style="{{ old('category', $item->category) !== $category ? 'display:none' : '' }}">
                    <legend class="mb-2 font-bold">{{ $categories[$category] }} details</legend>
                    @foreach($attributes as $key => $label)<label>{{ $label }}<input name="details[{{ $key }}]" maxlength="240" value="{{ old('details.'.$key, $item->getAttribute('attributes')[$key] ?? '') }}"></label>@endforeach
                </fieldset>
            @endforeach
            </section>
            <section class="panel form-stack listing-editor-section" aria-labelledby="listing-price-heading">
                <h2 id="listing-price-heading">2. Price</h2>
            <label>Price (CAD)<input name="price" type="number" value="{{ old('price', $item->price_cents !== null ? number_format($item->price_cents / 100, 2, '.', '') : '') }}" min="0" max="9999999.99" step=".01" required><small class="field-note">Enter 0 for a free item.</small></label>
            <label class="checkbox"><input type="checkbox" name="negotiable" value="1" @checked(old('negotiable', $item->negotiable))> Negotiable</label>
            </section>
            <section class="panel form-stack listing-editor-section" aria-labelledby="listing-photos-heading">
                <h2 id="listing-photos-heading">3. Photos</h2>
            @if($item->exists)
                <input type="hidden" name="manage_photos" value="1">
                <input type="hidden" name="photo_version" value="{{ $photoVersion }}">
                <div data-existing-photos>
                    <h3>Existing photos</h3>
                    <p class="field-note">Keep, remove or reorder existing photos. The first retained photo is the cover. New uploads are appended after retained photos. Changes only take effect when you submit.</p>
                    <div class="listing-photo-preview" data-existing-photo-grid>
                        @foreach($item->media as $photo)
                            <figure data-existing-photo="{{ $photo->id }}">
                                <img src="/marketplace/{{ $item->id }}/thumbnail/{{ $photo->id }}" alt="Existing listing photo {{ $loop->iteration }}" loading="lazy">
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
                </div>
            @endif
            <label>{{ $item->exists ? 'Add photos' : 'Photos' }}<input data-listing-photos type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple><small class="field-note">Up to five photos in total, JPEG, PNG or WebP, 4 MB each. Uncheck existing photos to make room for new ones.</small></label>
            <p class="field-note" data-photo-feedback role="status" aria-live="polite"></p>
            <div class="listing-photo-preview" data-photo-preview hidden></div>
            </section>
            <section class="panel form-stack listing-editor-section" aria-labelledby="listing-location-heading">
                <h2 id="listing-location-heading">4. Location</h2>
            @if($site->isNetwork())
                <label>Location<select name="location_id" required><option value="">Choose a Manitoba community</option>@foreach($locations as $place)<option value="{{ $place->id }}" @selected((int) old('location_id', $item->location_id ?? $suggestedLocation?->id) === $place->id)>{{ $place->name }}</option>@endforeach</select></label>
            @else
                <label>Location<input value="{{ $site->location->name }}" readonly></label>
                <p class="field-note">Posting for another Manitoba location? <a href="{{ \App\Support\NetworkUrl::to(\App\Support\NetworkUrl::main(), '/marketplace/new') }}">Visit CanadaMB.ca to choose your community.</a></p>
            @endif
            </section>
            <div class="panel listing-editor-submit"><p>Listings are reviewed before appearing publicly. Check your details before submitting.</p><button class="button button-primary">Submit for review</button></div>
        </form>
    </div>
</x-layouts.site>
