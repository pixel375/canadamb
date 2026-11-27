@props(['item' => null])
@if(\App\Support\EventSchedule::available())
    <fieldset class="form-stack">
        <legend>Event dates (Manitoba time)</legend>
        <div class="form-grid">
            <label>Start date<input type="date" name="starts_on" required value="{{ old('starts_on', $item?->localStart()->toDateString()) }}"></label>
            <label>Start time (optional)<input type="time" name="start_time" value="{{ old('start_time', $item && !$item->isDateOnly() ? $item->localStart()->format('H:i') : '') }}"></label>
            <label>Last date (optional)<input type="date" name="ends_on" value="{{ old('ends_on', $item ? substr($item->endInput() ?? '', 0, 10) : '') }}"></label>
            <label>End time (optional)<input type="time" name="end_time" value="{{ old('end_time', $item && !$item->isDateOnly() ? $item->localEnd()?->format('H:i') : '') }}"></label>
        </div>
        <p class="field-note">Leave both times empty if the event time is unknown or it is an all-day event. The last date is inclusive. For a timed event with an end time, leaving the last date empty uses the start date.</p>
    </fieldset>
@else
    <div class="form-grid">
        <label>Starts (Manitoba time)<input type="datetime-local" name="starts_at" required value="{{ old('starts_at', $item?->startInput()) }}"></label>
        <label>Ends (Manitoba time)<input type="datetime-local" name="ends_at" value="{{ old('ends_at', $item?->endInput()) }}"></label>
    </div>
@endif
