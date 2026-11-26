<x-layouts.admin title="SEO &amp; search visibility">
    <div class="page-intro"><div><p class="section-label">Experience &amp; configuration</p><h1>SEO &amp; search visibility</h1><p>Manage homepage metadata and review canonical website addresses.</p></div></div>
    <section class="panel form-stack">
        <h2>Indexing safeguards</h2>
        <p>{{ config('network.prelaunch') ? 'Private prelaunch — indexing is blocked.' : 'Public mode — check each portal and its published content.' }}</p>
        <p>Content detail pages use record-specific titles and canonical URLs. Canonical metadata does not redirect visitors. Private account and admin pages are not intended for indexing.</p>
        <p class="field-note">Saving metadata does not open a website, enable registration, change indexing protection or submit anything to search engines.</p>
    </section>
    @foreach($sites as $managed)
        <section class="panel form-stack">
            <h2>{{ $managed->name }} · {{ $managed->status }}</h2>
            <p>{{ $managed->domain?->host ?? 'Domain not configured' }}</p>
            <form method="post" action="/admin/seo/{{ $managed->id }}" class="form-stack">
                @csrf
                <input type="hidden" name="version" value="{{ $managed->configurationVersion() }}">
                <label>Homepage title<input name="seo_title" maxlength="120" value="{{ $managed->seo_title }}" placeholder="{{ $managed->name }} {{ $managed->location?->name ?? 'Manitoba' }} Community"></label>
                <label>Default meta description<textarea name="seo_description" maxlength="160" rows="3">{{ $managed->overrides['seo_description'] ?? '' }}</textarea></label>
                <div><p class="section-label">Current metadata preview</p><strong>{{ $managed->seo_title ?? $managed->name.' '.($managed->location?->name ?? 'Manitoba').' Community' }}</strong><p>{{ \Illuminate\Support\Str::limit($managed->overrides['seo_description'] ?? $managed->description, 160) }}</p></div>
                <button class="button button-primary">Save metadata</button>
            </form>
            @if($managed->domain && $managed->status === 'active')
                <div class="inline-actions"><a class="text-link" href="{{ \App\Support\NetworkUrl::to($managed, '/robots.txt') }}">Robots.txt</a><a class="text-link" href="{{ \App\Support\NetworkUrl::to($managed, '/sitemap.xml') }}">Sitemap</a></div>
            @endif
        </section>
    @endforeach
</x-layouts.admin>
