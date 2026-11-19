<x-layouts.site title="Saved jobs">
    <div class="container page-shell narrow">
        <div class="page-intro"><div><h1>Saved jobs</h1><p>Open positions you have bookmarked.</p></div></div>
        <div class="panel">
            @forelse($items as $item)<x-job-result :item="$item" :removable="true"/>@empty<p>No active saved jobs on this portal.</p>@endforelse
        </div>
        {{ $items->links() }}
    </div>
</x-layouts.site>
