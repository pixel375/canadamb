<x-layouts.admin title="Network overview">
    <div class="page-intro"><div><p class="section-label">Network administration</p><h1>Network overview</h1><p>Manage communities, review content and monitor recent activity.</p></div><a href="/admin/communities/new" class="button button-primary"><x-icon name="plus" size="16"/> Add a community</a></div>
    <section class="panel admin-readiness">
        <div><span class="section-label">Operating mode</span><h2>{{ $prelaunch ? 'Private prelaunch' : 'Prelaunch mode disabled' }}</h2><p>{{ $prelaunch ? 'Application configuration indicates prelaunch mode is enabled. External access and infrastructure require separate verification.' : 'Review production access controls and launch readiness separately.' }}</p></div>
        <a class="button button-outline" href="/admin/readiness">Launch readiness</a><span class="admin-mode-icon"><x-icon name="shield" size="28"/></span>
    </section>
    <div class="stat-grid admin-overview-stats">
        @foreach(['Configured portals' => $sites, 'Active in configuration' => $active, 'Supported places' => $locations, 'Accounts' => $accounts, 'Published configuration version' => $version] as $label => $value)
            <div class="panel"><strong>{{ number_format($value) }}</strong><small>{{ $label }}</small></div>
        @endforeach
    </div>
    <section class="admin-overview-section"><div class="section-heading"><div><p class="section-label">Review workload</p><h2>Moderation queues</h2></div><span class="discussion-count">{{ number_format(collect($queues)->sum('count')) }} pending actions</span></div>
        <div class="admin-queue-grid">
            @foreach($queues as $queue)
                <a class="panel admin-queue-card" href="{{ $queue['url'] }}"><span class="account-shortcut-icon"><x-icon :name="$queue['icon']" size="20"/></span><div><strong>{{ $queue['count'] }}</strong><small>{{ $queue['label'] }}</small></div><x-icon name="chevron" size="16"/></a>
            @endforeach
        </div>
    </section>
    <section class="panel admin-overview-section"><div class="section-heading"><h2>Recent administration activity</h2><a class="text-link" href="/admin/audit">Audit log</a></div>
        @forelse($recentAudit as $entry)<div class="item-row"><div><strong>{{ $entry->action }}</strong><p>{{ $entry->name ?? 'System' }}</p></div><time>{{ \Illuminate\Support\Carbon::parse($entry->created_at)->timezone('America/Winnipeg')->format('M j · g:i a') }}</time></div>@empty<p>No audited changes yet.</p>@endforelse
    </section>
</x-layouts.admin>
