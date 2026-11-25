<x-layouts.admin title="Commercial planning">
    <div class="page-intro"><div><p class="section-label">Monetization</p><h1>Advertising and subscriptions</h1><p>Prepare proposed CAD rates and inspect placement layouts before accepting payments.</p></div><a class="button button-outline" href="/admin/settings">Global settings</a></div>
    <p class="notice"><strong>Planning only.</strong> There is no live billing, ad delivery, checkout, subscription entitlement or paid ranking. Saving or publishing a rate card cannot activate these features.</p>
    <section class="panel form-stack"><h2>Proposed rate card</h2><p class="field-note">Blank means unpriced. Prices are Canadian dollars before applicable taxes. These are internal planning amounts, not customer offers.</p>
        <form method="post" action="/admin/commercial" class="form-stack">@csrf
            @foreach($catalog as $key => $product)
                <fieldset class="commercial-product"><legend>{{ $product['name'] }}</legend><p>{{ $product['description'] }}</p>
                    <div class="form-grid"><label>Proposed price (CAD / {{ $product['period'] }})<input type="number" name="prices[{{ $key }}]" min="0" max="100000" step="0.01" value="{{ old('prices.'.$key, $product['price_cents'] === null ? '' : number_format($product['price_cents'] / 100, 2, '.', '')) }}"></label><div><p class="section-label">Published internal rate</p><p>{{ $published[$key]['price_cents'] === null ? 'Not set' : 'CAD $'.number_format($published[$key]['price_cents'] / 100, 2) }}</p></div></div>
                </fieldset>
            @endforeach
            <button class="button button-primary">Save rate-card draft</button>
        </form>
        <p class="field-note">Rate cards use the existing versioned configuration. Publishing in Global settings also publishes other pending network settings; review them together first.</p>
    </section>
    <section class="panel form-stack mt-4"><h2>Private placement previews</h2><p>Use an approved business profile to inspect how separate, explicitly labelled placements might look. These previews are never rendered to ordinary visitors.</p>
        <form method="get" action="/admin/commercial" class="form-stack"><label>Preview business<select name="business"><option value="">Illustrative example</option>@foreach($businesses as $business)<option value="{{ $business->id }}" @selected($preview?->id === $business->id)>{{ $business->name }}</option>@endforeach</select></label><button class="button button-outline">Preview placement</button></form>
        <div class="commercial-preview-grid">
            @foreach(['directory-sponsored' => 'Business directory', 'home-banner' => 'Community homepage', 'marketplace-banner' => 'Marketplace'] as $key => $label)
                <div class="commercial-preview"><p class="section-label">{{ $label }} · private preview</p><div class="commercial-preview-content"><span class="commercial-sponsored-label">Sponsored · preview only</span><h3>{{ $preview?->name ?? 'Example local business' }}</h3><p>{{ \Illuminate\Support\Str::limit($preview?->description ?: 'A short message from a local advertiser.', 140) }}</p><span class="field-note">No live destination or impression tracking</span></div></div>
            @endforeach
        </div>
    </section>
    <section class="panel form-stack mt-4"><h2>Before accepting payments</h2><ul class="help-guidelines"><li>Approve pricing, tax and refund policies.</li><li>Implement isolated campaign and entitlement records, review workflow and expiry.</li><li>Verify payment-provider test mode, signed webhooks, retries and reconciliation.</li><li>Approve placement inventory and limits, privacy-conscious measurement, and advertiser terms.</li></ul><p>Public real estate advertising is outside this initial commercial release.</p></section>
</x-layouts.admin>
