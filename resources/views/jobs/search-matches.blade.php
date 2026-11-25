<x-layouts.site title="Saved job search matches">
    <div class="container page-shell">
        <div class="page-intro"><div><a class="text-link" href="/account/job-searches">Job searches and alerts</a><h1>{{ $item->name }}</h1><p>{{ $jobs->total() }} {{ $onlyNew ? 'new' : 'active' }} matching jobs</p></div></div>
        @if($item->alerts)<div class="panel inline-actions"><a class="button {{ $onlyNew ? 'button-primary' : 'button-outline' }}" href="?view=new">New matches</a><a class="button {{ !$onlyNew ? 'button-primary' : 'button-outline' }}" href="?view=all">All current matches</a>
            <form method="post" action="/account/job-searches/{{ $item->id }}/read">@csrf<input type="hidden" name="version" value="{{ $item->editVersion() }}"><input type="hidden" name="cutoff" value="{{ $cutoff->timestamp }}"><button class="button button-outline">Mark all current matches checked</button></form></div>@endif
        @if($item->alerts)<p class="field-note">Marking matches checked applies to this search up to the time this page loaded, including other result pages. Later postings stay new.</p>@endif
        <div class="panel">@forelse($jobs as $job)<x-job-result :item="$job" :show-description="true"/>@empty<p>No matching jobs right now.</p>@endforelse</div>
        {{ $jobs->links() }}
    </div>
</x-layouts.site>
