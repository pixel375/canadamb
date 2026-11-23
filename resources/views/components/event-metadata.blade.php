@props(['item' => null, 'useOld' => false])
@php
    $field = function (string $name, ?string $value = null) use ($useOld) {
        $input = $useOld ? old($name) : null;
        return is_string($input) ? $input : ($value ?? '');
    };
@endphp
<p class="field-note">Times use America/Winnipeg. Enter an end time for overnight or multiday events.</p>
<div class="event-fields">
    <label>Start date and time<input type="datetime-local" name="starts_at" required value="{{ $field('starts_at', $item?->localStart()->format('Y-m-d\TH:i')) }}"></label>
    <label>End date and time (optional)<input type="datetime-local" name="ends_at" value="{{ $field('ends_at', $item?->localEnd()?->format('Y-m-d\TH:i')) }}"></label>
    <label>Category<select name="category" required><option value="">Choose a category</option>
        @foreach(\App\Support\Community::EVENTS as $id => $label)
            <option value="{{ $id }}" @selected($field('category', $item?->category) === $id)>{{ $label }}</option>
        @endforeach
    </select></label>
    <label>Organizer<input name="organizer" required maxlength="150" value="{{ $field('organizer', $item?->organizer) }}"></label>
    <label>Venue<input name="venue" required maxlength="180" value="{{ $field('venue', $item?->venue) }}"></label>
    <label>Event link (optional HTTPS URL)<input type="url" name="event_url" maxlength="1024" value="{{ $field('event_url', $item?->event_url) }}"></label>
</div>
