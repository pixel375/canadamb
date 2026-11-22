@props(['label' => 'Message'])
@php
    $previousKey = old('idempotency_key');
    $key = is_string($previousKey) && \Illuminate\Support\Str::isUuid($previousKey) ? $previousKey : (string) \Illuminate\Support\Str::uuid();
    $body = old('body');
@endphp
<input type="hidden" name="idempotency_key" value="{{ $key }}">
<label>{{ $label }}<textarea name="body" maxlength="2000" rows="4">{{ is_string($body) ? $body : '' }}</textarea></label>
<label>Attachments <input type="file" name="attachments[]" multiple accept=".jpg,.jpeg,.png,.webp,.pdf">
    <small class="field-note">Add up to 3 photos or static PDFs. Maximum 5 MB each and 12 MB total.</small>
</label>
