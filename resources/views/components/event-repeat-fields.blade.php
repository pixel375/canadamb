@if(config('network.recurring_events'))
    <fieldset class="form-stack">
        <legend>Repeat event (optional)</legend>
        <label>Frequency<select name="repeat"><option value="none">One time</option><option value="weekly" @selected(old('repeat') === 'weekly')>Every week</option><option value="biweekly" @selected(old('repeat') === 'biweekly')>Every two weeks</option></select></label>
        <label>Number of occurrences, including the first<select name="occurrences">@for($i = 2; $i <= 8; $i++)<option value="{{ $i }}" @selected((int) old('occurrences', 2) === $i)>{{ $i }}</option>@endfor</select></label>
        <p class="field-note">Applies only when repeating. Each occurrence retains the same Manitoba wall-clock time and is submitted separately for moderation. Cancel individual dates from My events.</p>
    </fieldset>
@endif
