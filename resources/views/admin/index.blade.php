<x-layouts.admin title="Network overview">
    <div class="page-intro"><div><h1>Network overview</h1><p>Manage your communities from CanadaMB.</p></div><a href="/admin/communities/new" class="button button-primary"><x-icon name="plus" size="16"/> Add a community</a></div>
    <div class="stat-grid">
        @foreach(['Configured portals' => $sites, 'Active in configuration' => $active, 'Supported places' => $locations, 'Accounts' => $accounts, 'Listings pending review' => $pending, 'Published configuration version' => $version] as $label => $value)
            <div class="panel"><strong>{{ $value }}</strong><small>{{ $label }}</small></div>
        @endforeach
    </div>
</x-layouts.admin>
