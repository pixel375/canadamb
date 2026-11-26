<x-layouts.admin title="Community websites">
    <div class="page-intro"><div><h1>Community websites</h1><p>Review portal settings, appearance, moderation and deployment readiness.</p></div><a href="/admin/communities/new" class="button button-primary"><x-icon name="plus" size="16"/> Add a community</a></div>
    <section class="panel form-stack">
        <form method="get" action="/admin/communities" class="form-stack">
            <div class="form-grid">
                <label>Search portals<input type="search" name="q" maxlength="100" placeholder="Name, domain or community" value="{{ $filters['q'] ?? '' }}"></label>
                <label>Portal status<select name="status"><option value="">All statuses ({{ $counts->sum() }})</option>@foreach(\App\Models\Site::STATUSES as $status)<option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ str($status)->replace('_', ' ')->title() }} ({{ $counts[$status] ?? 0 }})</option>@endforeach</select></label>
                <label>DNS / TLS readiness<select name="readiness"><option value="">Any readiness</option><option value="confirmed" @selected(($filters['readiness'] ?? '') === 'confirmed')>Both confirmed</option><option value="incomplete" @selected(($filters['readiness'] ?? '') === 'incomplete')>Confirmation missing</option></select></label>
            </div>
            <div class="inline-actions"><button class="button button-primary">Filter portals</button><a href="/admin/communities" class="text-link">Clear filters</a></div>
        </form>
        <p class="field-note">{{ $sites->total() }} matching portal{{ $sites->total() === 1 ? '' : 's' }}. Readiness is based on saved operator confirmations, not a live DNS or TLS probe.</p>
    </section>
    <div class="admin-portal-grid">
        @forelse($sites as $community)
            @php
                $effective = $settings->forSite($community);
                $modules = collect($effective['modules'])->filter()->keys()->map(fn ($key) => \App\Support\NetworkSettings::MODULES[$key])->join(', ');
                $localRules = collect($community->overrides['manual_publication'] ?? [])->filter()->keys()->map(fn ($key) => \App\Support\PublicationPolicy::TYPES[$key] ?? $key);
                $localTheme = $community->overrides['experience']['preset'] ?? 'inherit';
            @endphp
            <article class="panel admin-portal-card" data-admin-portal="{{ $community->id }}">
                <div class="admin-portal-accent" style="background: {{ $community->accentCss() }}"></div>
                <div class="admin-portal-head">
                    <div><h2><a class="text-link" href="/admin/communities/{{ $community->id }}">{{ $community->name }}</a></h2><p>{{ $community->domain?->host ?? 'Domain missing' }} · {{ $community->location?->name ?? 'All Manitoba' }}</p></div>
                    <span class="member-listing-status" data-status="{{ $community->status }}">{{ str($community->status)->replace('_', ' ')->title() }}</span>
                </div>
                <dl class="admin-portal-facts">
                    <div><dt>DNS / TLS</dt><dd>{{ $community->domain?->dns_confirmed_at ? 'Confirmed' : 'Unchecked' }} / {{ $community->domain?->tls_confirmed_at ? 'Confirmed' : 'Unchecked' }}</dd></div>
                    <div><dt>Theme</dt><dd>{{ $localTheme === 'inherit' ? 'Network · ' : 'Local · ' }}{{ \App\Support\Themes::LABELS[$effective['preset']] ?? 'Custom' }}</dd></div>
                    <div><dt>Clock</dt><dd>{{ ($effective['local_clock_enabled'] ?? true) ? (($effective['clock_format'] ?? '12').' hour') : 'Hidden' }}</dd></div>
                    <div><dt>Weather</dt><dd>{{ \App\Support\LocalWeather::configured($effective) ? 'Configured' : 'Off' }}</dd></div>
                    <div><dt>Modules</dt><dd>{{ $modules ?: 'None' }}</dd></div>
                    <div><dt>Local manual review</dt><dd>{{ $localRules->join(', ') ?: 'Inherits network policy' }}</dd></div>
                </dl>
                <div class="inline-actions"><a class="button button-outline" href="/admin/communities/{{ $community->id }}">Manage portal</a><a class="text-link" href="/admin/communities/{{ $community->id }}/preview">Preview portal</a></div>
                <p class="field-note">Updated {{ $community->updated_at->format('Y-m-d') }}</p>
            </article>
        @empty
            <section class="panel"><p>No communities match these filters.</p></section>
        @endforelse
    </div>
    {{ $sites->links() }}
</x-layouts.admin>
