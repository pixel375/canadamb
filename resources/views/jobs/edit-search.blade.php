<x-layouts.site title="Edit saved job search">
    <div class="container page-shell narrow">
        <div class="page-intro"><div><a class="text-link" href="/account/job-searches">Job searches and alerts</a><h1>Edit saved job search</h1><p>Changing filters starts a new alert window. Existing alert preferences remain unchanged.</p></div></div>
        <form method="post" action="/account/job-searches/{{ $item->id }}" class="panel form-stack">@csrf @method('PUT')
            <input type="hidden" name="version" value="{{ old('version', $item->editVersion()) }}">
            <label>Search name<input name="name" required maxlength="80" value="{{ old('name', $item->name) }}"></label>
            <label>Keywords<input name="q" maxlength="100" value="{{ old('q', $item->filters['q'] ?? '') }}"></label>
            @if(request()->attributes->get('site')->isNetwork())<label>Location<input name="location" maxlength="150" value="{{ old('location', $item->filters['location'] ?? '') }}"></label>@endif
            <label>Employment type<select name="type"><option value="">All types</option>@foreach(\App\Support\JobBoard::TYPES as $id => $label)<option value="{{ $id }}" @selected(old('type', $item->filters['type'] ?? '') === $id)>{{ $label }}</option>@endforeach</select></label>
            <label>Workplace<select name="workplace"><option value="">Any workplace</option>@foreach(\App\Support\JobBoard::WORKPLACES as $id => $label)<option value="{{ $id }}" @selected(old('workplace', $item->filters['workplace'] ?? '') === $id)>{{ $label }}</option>@endforeach</select></label>
            <label>Pay period<select name="pay_unit"><option value="">Any pay period</option>@foreach(['hour' => 'Hourly', 'year' => 'Annual'] as $id => $label)<option value="{{ $id }}" @selected(old('pay_unit', $item->filters['pay_unit'] ?? '') === $id)>{{ $label }}</option>@endforeach</select></label>
            <label>Minimum pay (CAD)<input name="minimum_pay" type="number" min="0" step="0.01" value="{{ old('minimum_pay', $item->filters['minimum_pay'] ?? '') }}"></label>
            <div class="inline-actions"><button class="button button-primary">Save filters</button><a class="button button-outline" href="/account/job-searches">Cancel</a></div>
        </form>
    </div>
</x-layouts.site>
