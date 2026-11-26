<x-layouts.site title="Edit pending advertising request">
    <div class="container page-shell listing-editor">
        <div class="page-intro"><div><a class="text-link" href="/account/businesses/{{ $item->id }}/advertising">Advertising requests</a><h1>Edit pending advertising request</h1><p>{{ $item->name }} · Private planning only</p></div></div>
        <p class="notice">Changed requests use the current published planning rate. No payment, reservation or advertising is activated by saving this form.</p>
        <form class="panel form-stack" method="post" action="/account/businesses/{{ $item->id }}/advertising/{{ $campaign->id }}">@csrf @method('PUT')
            <input type="hidden" name="version" value="{{ old('version', $campaign->reviewVersion()) }}">
            @if(old('version') && old('version') !== $campaign->reviewVersion())<p class="notice notice-error">The request changed. <a href="/account/businesses/{{ $item->id }}/advertising/{{ $campaign->id }}/edit">Reload editor</a> before saving.</p>@endif
            <label>Portal<select name="site_id" required><option value="">Choose portal</option>@foreach($portals as $portal)<option value="{{ $portal->id }}" @selected((int) old('site_id', $campaign->site_id) === $portal->id)>{{ $portal->name }}</option>@endforeach</select></label>
            <label>Placement<select name="placement" required>@foreach(\App\Support\CommercialCatalog::PLACEMENTS as $key => $module)<option value="{{ $key }}" @selected(old('placement', $campaign->placement) === $key)>{{ $catalog[$key]['name'] }} · {{ $catalog[$key]['price_cents'] === null ? 'Unpriced' : 'CAD '.number_format($catalog[$key]['price_cents'] / 100, 2).' / week' }}</option>@endforeach</select></label>
            <label>Headline<input name="title" required maxlength="120" value="{{ old('title', $campaign->title) }}"></label>
            <label>Advertisement text<textarea name="body" required maxlength="500" rows="5">{{ old('body', $campaign->body) }}</textarea></label>
            <div class="form-grid"><label>Proposed start date<input type="date" name="starts_on" required min="{{ today('America/Winnipeg')->toDateString() }}" max="{{ today('America/Winnipeg')->addYear()->toDateString() }}" value="{{ old('starts_on', $campaign->starts_on->toDateString()) }}"></label><label>Weeks<input type="number" name="weeks" min="1" max="12" required value="{{ old('weeks', $campaign->weeks) }}"></label></div>
            <button class="button button-primary">Save pending request</button>
        </form>
    </div>
</x-layouts.site>
