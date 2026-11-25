<x-layouts.admin :title="$editing ? $managed->name : 'Add a community'">
    <div class="page-intro">
        <div><h1>{{ $editing ? $managed->name : 'Add a community' }}</h1><p>{{ $editing ? $managed->domain->host : 'Create a draft portal and review its settings before activation.' }}</p></div>
        @if($editing)<a class="button button-outline" href="/admin/communities/{{ $managed->id }}/preview">Preview portal <x-icon name="arrow" size="16"/></a>@endif
    </div>
    <form class="panel form-stack" method="post" action="{{ $editing ? '/admin/communities/'.$managed->id : '/admin/communities' }}">
        @csrf
        @if($editing)@method('PUT')<input type="hidden" name="version" value="{{ old('version', $managed->configurationVersion()) }}">@endif
        <h2>Identity</h2>
        <div class="form-grid">
            <label>Public name<input name="name" value="{{ old('name', $managed->name) }}" required maxlength="80"></label>
            <label>Subtitle<input name="subtitle" value="{{ old('subtitle', $managed->subtitle) }}" maxlength="120"></label>
        </div>
        @if(!$editing)
            <div class="form-grid">
                <label>Domain<input name="domain" value="{{ old('domain') }}" required maxlength="253" placeholder="example.ca"></label>
                <label>Manitoba location<select name="location_id" required><option value="">Choose a location</option>@foreach($places as $place)<option value="{{ $place->id }}" @selected((int) old('location_id') === $place->id)>{{ $place->name }}</option>@endforeach</select></label>
            </div>
        @else<p>Location · {{ $managed->location?->name ?? 'Province-wide' }}</p>@endif
        <label>Homepage headline<input name="tagline" value="{{ old('tagline', $managed->tagline) }}" maxlength="120"></label>
        <label>Site description<textarea name="description" required maxlength="500" rows="3">{{ old('description', $managed->description) }}</textarea></label>
        <div class="form-grid">
            <label>SEO title<input name="seo_title" value="{{ old('seo_title', $managed->seo_title) }}" maxlength="120"></label>
            <label>Contact email<input type="email" name="contact_email" value="{{ old('contact_email', $managed->contact_email) }}" maxlength="120"></label>
        </div>
        <label>Logo URL<input type="url" name="logo_url" value="{{ old('logo_url', $managed->logo_url) }}" maxlength="255" placeholder="https://"></label>

        @php
            $accent = $managed->accent;
            $accentType = old('accent_type', $accent['type']);
            $accentColors = old('accent_colors', implode(', ', $accent['colors']));
            $accentThickness = old('accent_thickness', $accent['thickness']);
            $accentDirection = old('accent_direction', $accent['direction']);
            $accentRadius = old('accent_radius', $accent['radius'] ?? 0);
            $accentShadow = (bool) old('accent_shadow', $accent['shadow'] ?? false);
        @endphp
        <fieldset class="form-stack" x-data="accentEditor(@js($accentType), @js($accentColors), @js($accentThickness), @js($accentDirection), @js($accentRadius), @js($accentShadow))">
            <legend class="font-bold">Header accent</legend>
            <div class="accent-preview"><div x-bind:style="preview()"></div></div>
            <div class="form-grid">
                <label>Style<select name="accent_type" x-model="type"><option value="solid" @selected($accentType === 'solid')>Solid</option><option value="gradient" @selected($accentType === 'gradient')>Gradient</option></select></label>
                <label>Color stops<span class="color-row"><input type="color" aria-label="Solid accent color" x-show="type === 'solid'" x-bind:value="colors" x-on:input="colors = $event.target.value"><input type="text" name="accent_colors" required value="{{ $accentColors }}" x-model="colors"></span><small class="field-note">Use HEX. Separate gradient stops with commas.</small></label>
            </div>
            <div class="form-grid">
                <label>Thickness (px)<input name="accent_thickness" type="number" value="{{ $accentThickness }}" min="2" max="8" x-model="size" required></label>
                <label>Direction (degrees)<input name="accent_direction" type="number" value="{{ $accentDirection }}" min="0" max="360" x-model="direction" required></label>
                <label>Radius (px)<input name="accent_radius" type="number" value="{{ $accentRadius }}" min="0" max="8" x-model="radius"></label>
            </div>
            <label class="checkbox"><input type="checkbox" name="accent_shadow" value="1" @checked($accentShadow) x-model="shadow"> Soft shadow</label>
        </fieldset>
        @unless($managed->isNetwork())
            @foreach(\App\Support\NetworkSettings::OPERATIONAL_MODULES as $key => $label)
                <label>{{ $label }}<select name="modules[{{ $key }}]">
                    <option value="inherit">Inherit network availability</option>
                    <option value="hide" @selected(old('modules.'.$key, ($managed->overrides['modules'][$key] ?? true) ? 'inherit' : 'hide') === 'hide')>Hide when local overrides are permitted</option>
                </select></label>
            @endforeach
        @endunless
        <div><button class="button button-primary">{{ $editing ? 'Save community' : 'Create draft' }}</button></div>
    </form>

    @if($editing)
        <div class="panel form-stack">
            <h2>Deployment readiness</h2>
            <p>At your DNS provider, point this domain to the network host. Add it as a parked domain in Hostinger and confirm a valid HTTPS certificate for this hostname.</p>
            <form method="post" action="/admin/communities/{{ $managed->id }}/readiness" class="form-stack">
                @csrf
                <input type="hidden" name="version" value="{{ $managed->readinessVersion() }}">
                <label class="checkbox"><input type="checkbox" name="dns" value="1" @checked($managed->domain->dns_confirmed_at)> I verified DNS routing.</label>
                <label class="checkbox"><input type="checkbox" name="tls" value="1" @checked($managed->domain->tls_confirmed_at)> I verified trusted HTTPS.</label>
                <div><button class="button button-outline">Save operator confirmations</button></div>
            </form>
            @unless($managed->isNetwork())
            <form method="post" action="/admin/communities/{{ $managed->id }}/status" class="form-grid">
                @csrf
                <input type="hidden" name="version" value="{{ $managed->readinessVersion() }}">
                <label>Public status<select name="status">@foreach(\App\Models\Site::STATUSES as $status)<option value="{{ $status }}" @selected($managed->status === $status)>{{ str($status)->replace('_', ' ')->title() }}</option>@endforeach</select></label>
                <button class="button button-primary">Update status</button>
            </form>
            @endunless
        </div>
    @endif
</x-layouts.admin>
