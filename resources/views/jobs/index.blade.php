<x-layouts.site title="Manitoba jobs">
    <div class="container page-shell narrow">
        <div class="page-intro"><div><h1>Jobs</h1><p>Browse active job postings in {{ request()->attributes->get('site')->location?->name ?? 'Manitoba' }}.</p></div>
            @auth <a href="/account/saved-jobs" class="button button-outline">Saved jobs</a> @endauth
        </div>
        <details class="search-disclosure" @if(!empty($filters)) open @endif>
            <summary><x-icon name="search" size="18"/> Search jobs <x-icon class="disclosure-arrow" name="chevron-down" size="17"/></summary>
            <form method="get" action="/jobs" class="panel filter-bar">
            <label>Search jobs<input type="search" name="q" maxlength="100" value="{{ $filters['q'] ?? '' }}"></label>
            @if(request()->attributes->get('site')->isNetwork())<label>Location<input type="search" name="location" maxlength="150" value="{{ $filters['location'] ?? '' }}"></label>@endif
            <label>Employment type<select name="type"><option value="">All types</option>@foreach(\App\Support\JobBoard::TYPES as $id => $label)<option value="{{ $id }}" @selected(($filters['type'] ?? null) === $id)>{{ $label }}</option>@endforeach</select></label>
            <label>Workplace<select name="workplace"><option value="">Any workplace</option>@foreach(\App\Support\JobBoard::WORKPLACES as $id => $label)<option value="{{ $id }}" @selected(($filters['workplace'] ?? null) === $id)>{{ $label }}</option>@endforeach</select></label>
            <label>Pay period<select name="pay_unit"><option value="">Any pay period</option><option value="hour" @selected(($filters['pay_unit'] ?? null) === 'hour')>Hourly</option><option value="year" @selected(($filters['pay_unit'] ?? null) === 'year')>Annual</option></select></label>
            <label>Minimum pay (CAD)<input name="minimum_pay" type="number" min="0" step="0.01" value="{{ $filters['minimum_pay'] ?? '' }}"></label>
            <button type="submit" class="button button-primary">Filter jobs</button>
        </form>
        </details>
        <div class="panel">
            @forelse($items as $item)<x-job-result :item="$item"/>@empty<p>No active jobs match these filters.</p>@endforelse
        </div>
        {{ $items->links() }}
    </div>
</x-layouts.site>
