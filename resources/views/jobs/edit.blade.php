<x-layouts.site title="Edit job posting">
    <div class="container page-shell narrow">
        <div class="page-intro"><div><a class="text-link" href="/account/businesses/{{ $employer->id }}/jobs?reference={{ $item->id }}">Back to posting</a><h1>Edit job posting</h1><p>Changes go back to moderation before they appear publicly.</p></div></div>
        <form method="post" action="/account/businesses/{{ $employer->id }}/jobs/{{ $item->id }}" class="panel form-stack">@csrf @method('PUT')
            <input type="hidden" name="version" value="{{ old('version', $item->reviewVersion()) }}">
            <p>Branch · {{ $item->branch->location->name }}</p>
            <x-job-fields :item="$item"/>
            @if(old('version') && old('version') !== $item->reviewVersion())
                <p class="notice notice-error">This posting changed. <a href="/account/businesses/{{ $employer->id }}/jobs/{{ $item->id }}/edit">Reload the editor</a> before continuing.</p>
            @endif
            <div class="inline-actions"><button class="button button-primary">Submit changes</button><a class="button button-outline" href="/account/businesses/{{ $employer->id }}/jobs?reference={{ $item->id }}">Cancel</a></div>
        </form>
    </div>
</x-layouts.site>
