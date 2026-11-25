@props(['name', 'label', 'autocomplete' => 'current-password', 'minimum' => null, 'fieldId' => null])
@php($fieldId = $fieldId ?? 'password-field-'.$name)
<div class="password-field">
    <label for="{{ $fieldId }}">{{ $label }}</label>
    <div class="password-input-wrap">
        <input id="{{ $fieldId }}" name="{{ $name }}" type="password" required autocomplete="{{ $autocomplete }}" @if($minimum) minlength="{{ $minimum }}" @endif>
        <button type="button" class="button button-outline password-visibility" data-password-toggle aria-controls="{{ $fieldId }}" aria-pressed="false" hidden>Show</button>
    </div>
    @error($name)<small class="field-note" role="alert">{{ $message }}</small>@enderror
</div>
