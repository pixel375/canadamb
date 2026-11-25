<x-layouts.site title="Saved job searches">
    <div class="container page-shell">
        <div class="page-intro"><div><a class="text-link" href="/account/saved-jobs">Saved jobs</a><h1>Job searches and alerts</h1><p>Manage saved searches for this portal. Alerts show newly published, still-open matches in your account; no emails are sent.</p></div><a class="button button-primary" href="/jobs">Browse jobs</a></div>
        <x-saved-navigation current="job-searches" :total="$items->count()"/>
        <section class="seller-listing-grid">
            @forelse($items as $item)
                <article class="panel seller-listing-card form-stack">
                    <div><h2>{{ $item->name }}</h2><p>{{ $item->alerts ? $counts[$item->id].' new '.\Illuminate\Support\Str::plural('match', $counts[$item->id]) : 'Alerts paused' }}</p><p class="field-note">{{ count($item->filters) ? count($item->filters).' saved filters' : 'All open jobs on this portal' }}</p></div>
                    <div class="inline-actions"><a class="button button-primary" href="/account/job-searches/{{ $item->id }}">View matches</a><a class="text-link" href="/jobs?{{ http_build_query($item->filters) }}">Open original search</a></div>
                    <details><summary>Search and alert preferences</summary><form class="form-stack mt-2" method="post" action="/account/job-searches/{{ $item->id }}/preferences">@csrf
                        <label>Name<input name="name" required maxlength="80" value="{{ $item->name }}"></label>
                        <label>New job alerts<select name="alerts"><option value="1" @selected($item->alerts)>On</option><option value="0" @selected(!$item->alerts)>Paused</option></select></label>
                        <p class="field-note">Resuming alerts starts a fresh matching window.</p><button class="button button-outline">Save preferences</button>
                    </form></details>
                    <details><summary>Remove saved search</summary><form method="post" action="/account/job-searches/{{ $item->id }}">@csrf @method('DELETE')<button class="button button-outline">Confirm removal</button></form></details>
                </article>
            @empty<div class="panel"><h2>No saved searches on this portal.</h2><p>Filter the job board and select Save this job search.</p></div>@endforelse
        </section>
        <p class="field-note">Up to ten searches across the network. To manage a search saved on another portal, return to that portal.</p>
    </div>
</x-layouts.site>
