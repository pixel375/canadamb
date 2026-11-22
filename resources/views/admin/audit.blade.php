<x-layouts.admin title="Audit log">
    <div class="page-intro"><div><h1>Audit log</h1><p>Configuration changes and moderation actions.</p></div></div>
    <div class="panel table-wrap"><table><thead><tr><th>Action</th><th>Actor</th><th>Site</th><th>Date</th></tr></thead><tbody>
        @forelse($entries as $entry)
            <tr>
                <td>{{ $entry->action }}</td>
                <td>{{ in_array($entry->action, ['message.reported', 'moderation.report-submitted'], true) ? 'Reporter protected' : ($entry->name ?? 'System') }}</td>
                <td>{{ $entry->site_id ?? 'Network' }}</td>
                <td>{{ $entry->created_at }}</td>
            </tr>
        @empty<tr><td colspan="4">No audited changes yet.</td></tr>@endforelse
    </tbody></table></div>
    {{ $entries->links() }}
</x-layouts.admin>
