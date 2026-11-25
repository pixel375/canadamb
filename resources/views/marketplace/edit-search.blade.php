<x-layouts.site title="Edit saved Marketplace search">
    <div class="container page-shell narrow">
        <div class="page-intro"><div><a class="text-link" href="/account/marketplace-searches">Saved Marketplace searches</a><h1>Edit saved Marketplace search</h1><p>Choose the filters you want to reuse on this portal.</p></div></div>
        <form method="post" action="/account/marketplace-searches/{{ $item->id }}" class="panel form-stack">@csrf @method('PUT')
            <input type="hidden" name="version" value="{{ old('version', $item->editVersion()) }}">
            <label>Search name<input name="name" required maxlength="80" value="{{ old('name', $item->name) }}"></label>
            <label>Keywords<input name="q" maxlength="100" value="{{ old('q', $item->filters['q'] ?? '') }}"></label>
            @if(request()->attributes->get('site')->isNetwork())<label>Location<select name="location"><option value="">All Manitoba</option>@foreach($places as $place)<option value="{{ $place->slug }}" @selected(old('location', $item->filters['location'] ?? '') === $place->slug)>{{ $place->name }}</option>@endforeach</select></label>@endif
            <label>Category group<select name="group"><option value="">All groups</option>@foreach($groups as $id => $group)<option value="{{ $id }}" @selected(old('group', $item->filters['group'] ?? '') === $id)>{{ $group['name'] }}</option>@endforeach</select></label>
            <label>Specific category<select name="category"><option value="">Any category</option>@foreach($groups as $group)<optgroup label="{{ $group['name'] }}">@foreach($group['available'] as $id => $label)<option value="{{ $id }}" @selected(old('category', $item->filters['category'] ?? '') === $id)>{{ $label }}</option>@endforeach</optgroup>@endforeach</select></label>
            <p class="field-note">A specific category takes precedence over the group.</p>
            <label>Condition<select name="condition"><option value="">Any condition</option>@foreach(\App\Support\Marketplace::CONDITIONS as $id => $label)<option value="{{ $id }}" @selected(old('condition', $item->filters['condition'] ?? '') === $id)>{{ $label }}</option>@endforeach</select></label>
            <div class="form-grid"><label>Minimum price (CAD)<input name="min_price" type="number" min="0" step="0.01" value="{{ old('min_price', $item->filters['min_price'] ?? '') }}"></label><label>Maximum price (CAD)<input name="max_price" type="number" min="0" step="0.01" value="{{ old('max_price', $item->filters['max_price'] ?? '') }}"></label></div>
            <div class="inline-actions"><button class="button button-primary">Save filters</button><a class="button button-outline" href="/account/marketplace-searches">Cancel</a></div>
        </form>
    </div>
</x-layouts.site>
