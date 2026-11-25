<x-layouts.site title="Reuse job posting">
    <div class="container page-shell listing-editor">
        <div class="page-intro"><div><a class="text-link" href="/account/businesses/{{ $employer->id }}/jobs">Manage jobs</a><h1>Reuse job posting</h1><p>Create a new submission from an existing posting. The original is unchanged. Choose a new closing date and review all details.</p></div></div>
        <form method="post" action="/account/businesses/{{ $employer->id }}/jobs" class="panel form-stack">@csrf
            <label>Business branch<select name="business_location_id" required>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected((int) old('business_location_id', $item->business_location_id) === $branch->id)>{{ $branch->location->name }} · {{ $branch->address }}</option>@endforeach</select></label>
            <x-job-fields :item="$item"/>
            <button class="button button-primary">Submit new posting for review</button>
        </form>
    </div>
</x-layouts.site>
