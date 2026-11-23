@php($site = request()->attributes->get('site'))
<x-layouts.site :title="$item->exists ? 'Edit listing' : 'Post a listing'">
    <div class="container page-shell narrow">
        <div class="page-intro"><div><h1>{{ $item->exists ? 'Edit listing' : 'Post a listing' }}</h1><p>Basic listings are free and reviewed before publication.</p></div></div>
        <form class="panel form-stack" method="post" enctype="multipart/form-data" action="{{ $item->exists ? '/account/listings/'.$item->id : '/marketplace' }}" x-data="{ category: @js(old('category', $item->category ?? '')) }">
            @csrf
            @if($item->exists)@method('PUT')@endif
            <label>Title<input name="title" value="{{ old('title', $item->title) }}" required maxlength="120"></label>
            <label>Description<textarea name="description" rows="5" required maxlength="5000">{{ old('description', $item->description) }}</textarea></label>
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
            <label>Price (CAD)<input name="price" type="number" value="{{ old('price', $item->exists ? number_format($item->price_cents / 100, 2, '.', '') : '') }}" min="0" max="9999999.99" step=".01" required><small class="field-note">Enter 0 for a free item.</small></label>
            <label class="checkbox"><input type="checkbox" name="negotiable" value="1" @checked(old('negotiable', $item->negotiable))> Negotiable</label>
            <label>Photos<input type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple><small class="field-note">Up to five JPEG, PNG or WebP photos, 4 MB each. {{ $item->exists ? 'Selecting new photos replaces the current set.' : '' }}</small></label>
            @if($site->isNetwork())
                <label>Location<select name="location_id" required><option value="">Choose a Manitoba community</option>@foreach($locations as $place)<option value="{{ $place->id }}" @selected((int) old('location_id', $item->location_id) === $place->id)>{{ $place->name }}</option>@endforeach</select></label>
            @else
                <label>Location<input value="{{ $site->location->name }}" readonly></label>
                <p class="field-note">Posting for another Manitoba location? <a href="{{ \App\Support\NetworkUrl::to(\App\Support\NetworkUrl::main(), '/marketplace/new') }}">Visit CanadaMB.ca to choose your community.</a></p>
            @endif
            <div><button class="button button-primary">Submit for review</button></div>
        </form>
    </div>
</x-layouts.site>
