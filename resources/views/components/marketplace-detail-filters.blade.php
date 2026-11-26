@props(['filters'])
@php($detailFields = \App\Support\MarketplaceDetailFilters::FIELDS)
<fieldset class="form-stack" x-show="@js(['cars', 'rvs', 'farm', 'electronics']).includes(detailCategory)" x-cloak>
    <legend class="font-bold">Category-specific filters</legend>
    <p class="field-note">Match seller-provided details. Listings without the selected details are excluded.</p>
    @foreach($detailFields as $key => $field)
        <label x-show="@js($field['categories']).includes(detailCategory)">
            {{ $field['label'] }}
            <input name="{{ $key }}" value="{{ old($key, $filters[$key] ?? '') }}" type="{{ $field['type'] === 'text' ? 'text' : 'number' }}"
                x-bind:disabled="!@js($field['categories']).includes(detailCategory)"
                @if($field['type'] === 'text') maxlength="80" @else min="{{ $field['min'] }}" max="{{ $field['max'] }}" step="{{ $field['type'] === 'integer' ? '1' : 'any' }}" @endif>
        </label>
    @endforeach
</fieldset>
