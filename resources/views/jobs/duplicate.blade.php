<x-layouts.site title="Reuse job posting">
    <div class="container page-shell listing-editor">
        <div class="page-intro"><div><a class="text-link" href="/account/businesses/{{ $employer->id }}/jobs">Manage jobs</a><h1>Reuse job posting</h1><p>Create a new submission from an existing posting. The original is unchanged. Choose a new closing date and review all details.</p></div></div>
        @if($branches->isEmpty())<p class="notice">No open branches on this portal. Choose a portal with an open branch before reusing this posting.</p>@else
        <form method="post" action="/account/businesses/{{ $employer->id }}/jobs" class="panel form-stack">@csrf
            <x-shared-publication-note/>
            <label>Business branch<select name="business_location_id" required>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected((int) old('business_location_id', $item->business_location_id) === $branch->id)>{{ $branch->location->name }} · {{ $branch->address }}</option>@endforeach</select></label>
            <x-job-fields :item="$item"/>
            <button class="button button-primary">Submit new posting for review</button>
        </form>@endif
    </div>
</x-layouts.site>
