<x-layouts.admin title="Audit log">
    <div class="page-intro"><div><h1>Audit log</h1><p>Configuration changes and moderation actions.</p></div></div>
    <form method="get" action="/admin/audit" class="panel admin-audit-filter"><label>Search action or actor<input type="search" name="q" maxlength="100" value="{{ $filters['q'] ?? '' }}"></label><label>Period<select name="period"><option value="">All time</option>@foreach([7,30,90] as $days)<option value="{{ $days }}" @selected((string) ($filters['period'] ?? '') === (string) $days)>Last {{ $days }} days</option>@endforeach</select></label><button class="button button-primary">Filter audit log</button><a class="text-link" href="/admin/audit">Reset</a></form>
    <p class="field-note seller-results-count">{{ $entries->total() }} audit entries</p>
    <div class="panel table-wrap"><table><thead><tr><th>Action</th><th>Actor</th><th>Site</th><th>Date</th></tr></thead><tbody>
        @forelse($entries as $entry)
            <tr><td>{{ $entry->action }}</td><td>{{ $entry->name ?? 'System' }}</td><td>{{ $entry->site_id ?? 'Network' }}</td><td>{{ $entry->created_at }}</td></tr>
        @empty<tr><td colspan="4">No audited changes yet.</td></tr>@endforelse
    </tbody></table></div>
    {{ $entries->links() }}
</x-layouts.admin>
