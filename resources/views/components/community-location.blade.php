@props(['places', 'allowNetwork' => false, 'suggestedLocation' => null])
@php($site = request()->attributes->get('site'))
@if($site->isNetwork())
    <label>Location<select name="location_id" @required(!$allowNetwork)><option value="">{{ $allowNetwork ? 'Province-wide discussion' : 'Choose a Manitoba community' }}</option>
        @foreach($places as $place)<option value="{{ $place->id }}" @selected((string) old('location_id', $suggestedLocation?->id) === (string) $place->id)>{{ $place->name }}</option>@endforeach
    </select></label>
@else
    <p>Location · {{ $site->location->name }}</p>
@endif
