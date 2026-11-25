@props(['item' => null])
<label>Title<input name="title" required maxlength="140" value="{{ old('title', $item?->title) }}"></label>
<div class="form-grid">
    <label>Employment type<select name="employment_type" required><option value="">Choose a type</option>@foreach(\App\Support\JobBoard::TYPES as $id => $label)<option value="{{ $id }}" @selected(old('employment_type', $item?->employment_type) === $id)>{{ $label }}</option>@endforeach</select></label>
    <label>Workplace<select name="workplace" required><option value="">Choose a workplace</option>@foreach(\App\Support\JobBoard::WORKPLACES as $id => $label)<option value="{{ $id }}" @selected(old('workplace', $item?->workplace) === $id)>{{ $label }}</option>@endforeach</select></label>
    <label>Closing date<input name="closing_on" type="date" required value="{{ old('closing_on', $item?->closing_on?->toDateString()) }}"></label>
</div>
<div class="form-grid">
    <label>Pay period<select name="pay_unit"><option value="">Undisclosed</option><option value="hour" @selected(old('pay_unit', $item?->pay_unit) === 'hour')>Hourly</option><option value="year" @selected(old('pay_unit', $item?->pay_unit) === 'year')>Annual</option></select></label>
    <label>Minimum pay (CAD)<input name="pay_min" type="number" min="0" step="0.01" value="{{ old('pay_min', $item?->pay_min === null ? '' : $item->pay_min / 100) }}"></label>
    <label>Maximum pay (CAD)<input name="pay_max" type="number" min="0" step="0.01" value="{{ old('pay_max', $item?->pay_max === null ? '' : $item->pay_max / 100) }}"></label>
</div>
<label>Application page<input type="url" name="apply_url" required maxlength="1024" placeholder="https://" value="{{ old('apply_url', $item?->apply_url) }}"></label>
<label>Job description<textarea data-character-count name="description" required minlength="20" maxlength="6000" rows="7">{{ old('description', $item?->description) }}</textarea><small class="field-note" data-character-feedback></small></label>
