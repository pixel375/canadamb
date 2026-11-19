@php($site = request()->attributes->get('site'))
<x-layouts.site :title="$business->exists ? 'Manage '.$business->name : 'Add a business'">
    <div class="container page-shell narrow">
        <div class="page-intro"><div><h1>{{ $business->exists ? $business->name : 'Add a business' }}</h1><a class="text-link mt-2" href="/account/businesses">My businesses</a></div></div>
        <form class="panel form-stack" method="post" action="{{ $business->exists ? '/account/businesses/'.$business->id : '/account/businesses' }}">
            @csrf
            @if($business->exists)@method('PUT')
            @else
                <label>Business name<input name="name" value="{{ old('name') }}" required maxlength="140"></label>
                <label>Category<select name="category" required><option value="">Choose a category</option>@foreach($categories as $key => $category)<option value="{{ $key }}" @selected(old('category') === $key)>{{ $category }}</option>@endforeach</select></label>
                <label>Public business address<input name="address" value="{{ old('address') }}" maxlength="255" required></label>
                @if($site->isNetwork())<label>Location<select name="location_id" required><option value="">Choose a Manitoba community</option>@foreach($places as $place)<option value="{{ $place->id }}" @selected((int) old('location_id') === $place->id)>{{ $place->name }}</option>@endforeach</select></label>
                @else
                    <label>Location<input value="{{ $site->location->name }}" readonly></label>
                    <p class="field-note">Posting for another Manitoba location? <a href="{{ \App\Support\NetworkUrl::to(\App\Support\NetworkUrl::main(), '/account/businesses/new') }}">Visit CanadaMB.ca to choose your community.</a></p>
                @endif
            @endif
            <label>Description<textarea name="description" rows="5" maxlength="2500">{{ old('description', $business->description) }}</textarea></label>
            <label>Hours<textarea name="hours" rows="3" maxlength="1000">{{ old('hours', $business->hours) }}</textarea></label>
            <label>Website<input type="url" name="website" maxlength="255" value="{{ old('website', $business->website) }}"></label>
            <label>Public contact email<input type="email" name="public_email" maxlength="254" value="{{ old('public_email', $business->public_email) }}"></label>
            <label>Public phone<input type="tel" name="phone" maxlength="32" value="{{ old('phone', $business->phone) }}"></label>
            <div><button class="button button-primary">{{ $business->exists ? 'Save profile' : 'Submit for review' }}</button></div>
        </form>
        @if($business->status === 'active' && $branches->isNotEmpty())
            <form class="panel form-stack" method="post" action="/account/businesses/{{ $business->id }}/updates">@csrf
                <h2>Publish a business update</h2>
                <label>Branch<select name="business_location_id" required>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected((int) old('business_location_id') === $branch->id)>{{ $branch->location->name }} · {{ $branch->address }}</option>@endforeach</select></label>
                <label>Type<select name="type" required>@foreach(['announcement', 'promotion', 'update'] as $type)<option value="{{ $type }}" @selected(old('type') === $type)>{{ ucfirst($type) }}</option>@endforeach</select></label>
                <label>Title<input name="title" value="{{ old('title') }}" maxlength="140" required></label>
                <label>Update<textarea name="body" rows="5" maxlength="3000" required>{{ old('body') }}</textarea></label>
                <div><button class="button button-primary">Submit update for review</button></div>
            </form>
        @endif
    </div>
</x-layouts.site>
