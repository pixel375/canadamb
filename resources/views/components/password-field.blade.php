@props(['name', 'label', 'autocomplete' => 'current-password', 'minimum' => null])
<div class="password-field">
    <label for="password-field-{{ $name }}">{{ $label }}</label>
    <div class="password-input-wrap">
        <input id="password-field-{{ $name }}" name="{{ $name }}" type="password" required autocomplete="{{ $autocomplete }}" @if($minimum) minlength="{{ $minimum }}" @endif>
        <button type="button" class="button button-outline password-visibility" data-password-toggle aria-controls="password-field-{{ $name }}" aria-pressed="false" hidden>Show</button>
    </div>
    @error($name)<small class="field-note" role="alert">{{ $message }}</small>@enderror
</div>
