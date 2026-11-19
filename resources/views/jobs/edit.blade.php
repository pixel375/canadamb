<x-layouts.site title="Edit job posting">
    <div class="container page-shell narrow">
        <div class="page-intro"><div><h1>Edit job posting</h1><p>Changes go back to moderation before they appear publicly.</p></div></div>
        <form method="post" action="/account/businesses/{{ $employer->id }}/jobs/{{ $item->id }}" class="panel form-stack">@csrf @method('PUT')
            <p>Branch · {{ $item->branch->location->name }}</p>
            <x-job-fields :item="$item"/>
            <div><button class="button button-primary">Submit changes</button></div>
        </form>
    </div>
</x-layouts.site>
