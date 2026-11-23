@php
    $site = request()->attributes->get('site');
    $field = static function (string $name, mixed $fallback): mixed {
        $value = old($name, $fallback);

        return is_scalar($value) || $value === null ? $value : $fallback;
    };
    $categoryGroups = \App\Support\MarketplaceCategories::grouped($categories->all());
    $selectedCategory = $field('category', $item->category ?? '');
    $submittedVersion = $field('photo_version', null);
    $editVersion = is_string($submittedVersion) && preg_match('/\A[a-f0-9]{64}\z/', $submittedVersion)
        ? $submittedVersion : $photoVersion;
@endphp
<x-layouts.site :title="$item->exists ? 'Edit listing' : 'Post a listing'">
    <div class="container page-shell narrow">
        <div class="page-intro"><div><h1>{{ $item->exists ? 'Edit listing' : 'Post a listing' }}</h1><p>{{ $item->exists ? 'Changes to listing details or photos go through review.' : 'Basic listings are free and reviewed before publication.' }}</p></div></div>
        <form class="panel form-stack" method="post" enctype="multipart/form-data" action="{{ $item->exists ? '/account/listings/'.$item->id : '/marketplace' }}" x-data="{ category: @js($selectedCategory) }">
            @csrf
            @if($item->exists)@method('PUT')@endif
            @if($item->exists)
                <input type="hidden" name="photo_version" value="{{ $editVersion }}">
            @endif
            <label>Title<input name="title" value="{{ $field('title', $item->title) }}" required maxlength="120"></label>
            <label>Description<textarea name="description" rows="5" required maxlength="5000">{{ $field('description', $item->description) }}</textarea></label>
            <div class="form-grid">
                <label>Category<select name="category" x-model="category" required><option value="">Choose a category</option>
                    @foreach($categoryGroups as $group)
                        <optgroup label="{{ $group['name'] }}">
                            @foreach($group['available'] as $key => $label)<option value="{{ $key }}" @selected($selectedCategory === $key)>{{ $label }}</option>@endforeach
                        </optgroup>
                    @endforeach
                </select><small class="field-note">Choose the most specific subcategory. Public real estate listings are not yet available.</small></label>
                <label>Condition<select name="condition" required><option value="">Choose a condition</option>@foreach($conditions as $key => $label)<option value="{{ $key }}" @selected($field('condition', $item->condition) === $key)>{{ $label }}</option>@endforeach</select></label>
            </div>
            @foreach($fields as $category => $attributes)
                <fieldset class="form-grid" x-show="category === @js($category)" x-bind:disabled="category !== @js($category)"
                    @disabled($selectedCategory !== $category) style="{{ $selectedCategory !== $category ? 'display:none' : '' }}">
                    <legend class="mb-2 font-bold">{{ $categories[$category] }} details</legend>
                    @foreach($attributes as $key => $label)<label>{{ $label }}<input name="details[{{ $key }}]" maxlength="240" value="{{ $field('details.'.$key, $item->getAttribute('attributes')[$key] ?? '') }}"></label>@endforeach
                </fieldset>
            @endforeach
            <label>Price (CAD)<input name="price" type="number" value="{{ $field('price', $item->exists ? number_format($item->price_cents / 100, 2, '.', '') : '') }}" min="0" max="9999999.99" step=".01" required><small class="field-note">Enter 0 for a free item.</small></label>
            <label class="checkbox"><input type="checkbox" name="negotiable" value="1" @checked($field('negotiable', $item->negotiable))> Negotiable</label>
            <fieldset class="listing-photo-editor" data-photo-editor>
                <legend>Photos</legend>
                <p id="listing-photo-limits" class="field-note">Up to five JPEG, PNG or WebP photos, 4 MB each.</p>
                @if(old('photo_plan') !== null)
                    <p class="field-note">Photo choices were reset. Choose any new files and make your photo changes again.</p>
                @endif
                <div data-photo-native>
                    <label for="listing-photo-picker">Choose photos</label>
                    <input id="listing-photo-picker" type="file" name="photos[]" accept="image/jpeg,image/png,image/webp"
                        multiple aria-describedby="listing-photo-limits{{ $item->exists ? ' listing-photo-fallback' : '' }}" data-photo-picker>
                    @if($item->exists)
                        <p id="listing-photo-fallback" class="field-note">Choosing new files replaces all current photos. Leave this field empty to keep the current photos.</p>
                    @endif
                </div>
                <div class="listing-photo-actions" data-photo-actions hidden>
                    <button type="button" class="button button-outline" data-photo-add>Add photos</button>
                    <button type="button" class="button button-outline" data-photo-replace hidden>Replace all photos</button>
                </div>
                <input type="file" name="photos[]" data-photo-files multiple hidden disabled>
                <input type="hidden" name="photo_plan" data-photo-plan disabled>
                <p class="field-note" data-photo-count hidden></p>
                <p class="listing-photo-error" role="alert" data-photo-error></p>
                <p class="sr-only" role="status" data-photo-status></p>
                <ol class="listing-photo-previews" data-photo-previews @if(!$item->exists || $item->media->isEmpty()) hidden @endif>
                    @if($item->exists)
                        @foreach($item->media as $photo)
                            <li data-photo-entry="existing:{{ $photo->id }}">
                                <img src="/marketplace/{{ $item->id }}/thumbnail/{{ $photo->id }}" alt="" loading="lazy">
                                <span>Current photo {{ $loop->iteration }}{{ $loop->first ? ' · Cover' : '' }}</span>
                            </li>
                        @endforeach
                    @endif
                </ol>
                <template data-photo-template>
                    <li>
                        <img alt="">
                        <span data-photo-name></span>
                        <div class="listing-photo-controls">
                            <button type="button" class="button button-outline" data-photo-earlier>Move earlier</button>
                            <button type="button" class="button button-outline" data-photo-later>Move later</button>
                            <button type="button" class="button button-outline" data-photo-replace-one>Replace</button>
                            <button type="button" class="button button-outline" data-photo-remove>Remove</button>
                        </div>
                    </li>
                </template>
            </fieldset>
            @if($site->isNetwork())
                <label>Location<select name="location_id" required><option value="">Choose a Manitoba community</option>@foreach($locations as $place)<option value="{{ $place->id }}" @selected((int) $field('location_id', $item->location_id) === $place->id)>{{ $place->name }}</option>@endforeach</select></label>
            @else
                <label>Location<input value="{{ $site->location->name }}" readonly></label>
                <p class="field-note">Posting for another Manitoba location? <a href="{{ \App\Support\NetworkUrl::to(\App\Support\NetworkUrl::main(), '/marketplace/new') }}">Visit CanadaMB.ca to choose your community.</a></p>
            @endif
            <div><button class="button button-primary">{{ $item->exists ? 'Save changes' : 'Submit for review' }}</button></div>
        </form>
    </div>
</x-layouts.site>
