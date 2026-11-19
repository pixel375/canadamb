<x-layouts.admin title="Community websites">
    <div class="page-intro"><div><h1>Community websites</h1><p>Review portal settings, accents and deployment readiness.</p></div><a href="/admin/communities/new" class="button button-primary"><x-icon name="plus" size="16"/> Add a community</a></div>
    <div class="panel table-wrap">
        <table>
            <thead><tr><th>Portal</th><th>Status</th><th>Enabled</th><th>DNS / TLS</th><th>Updated</th></tr></thead>
            <tbody>
            @foreach($sites as $community)
                <tr>
                    <td><div class="mb-1 h-1 w-10 rounded" style="background: {{ $community->accentCss() }}"></div><a href="/admin/communities/{{ $community->id }}" class="text-link">{{ $community->name }}</a><p>{{ $community->domain->host }} · {{ $community->location?->name ?? 'All Manitoba' }}</p></td>
                    <td>{{ str($community->status)->replace('_', ' ')->title() }}</td>
                    <td>{{ collect($settings->forSite($community)['modules'])->filter()->keys()->map(fn ($key) => \App\Support\NetworkSettings::MODULES[$key])->join(', ') ?: 'None' }}</td>
                    <td>{{ $community->domain->dns_confirmed_at ? 'Confirmed' : 'Unchecked' }} / {{ $community->domain->tls_confirmed_at ? 'Confirmed' : 'Unchecked' }}</td>
                    <td>{{ $community->updated_at->format('Y-m-d') }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    {{ $sites->links() }}
</x-layouts.admin>
