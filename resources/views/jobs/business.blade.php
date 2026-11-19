<x-layouts.site :title="$employer->name.' jobs'">
    <div class="container page-shell narrow">
        <div class="page-intro"><div><h1>{{ $employer->name }} jobs</h1><p>Manage postings for your business branches here.</p></div><a href="/account/businesses/{{ $employer->id }}" class="button button-outline">Business dashboard</a></div>
        @if($branches->isNotEmpty())
            <details class="panel" @if($errors->any()) open @endif><summary>Post a job</summary>
                <form method="post" action="/account/businesses/{{ $employer->id }}/jobs" class="form-stack mt-3">@csrf
                    <label>Business branch<select name="business_location_id" required>
                        @foreach($branches as $branch)<option value="{{ $branch->id }}" @selected((string) old('business_location_id') === (string) $branch->id)>{{ $branch->location->name }} · {{ $branch->address }}</option>@endforeach
                    </select></label>
                    <x-job-fields/>
                    <div><button class="button button-primary">Submit for review</button></div>
                </form>
            </details>
        @else <p class="notice">This business has no branches in the current portal.</p> @endif
        <section class="panel"><h2>Your job postings</h2>
            @forelse($items as $item)
                <article class="item-row"><div>
                    <h3>{{ $item->title }}</h3><p>{{ ucfirst($item->status) }} · {{ $item->branch->location->name }} · Closes {{ $item->closing_on->format('M j, Y') }}</p>
                </div><div class="inline-actions">
                    @if(in_array($item->status, ['pending', 'approved', 'rejected'], true))<a class="button button-outline" href="/account/businesses/{{ $employer->id }}/jobs/{{ $item->id }}/edit">Edit</a>@endif
                    @if(in_array($item->status, ['pending', 'approved'], true))<form method="post" action="/account/businesses/{{ $employer->id }}/jobs/{{ $item->id }}/withdraw">@csrf<button class="button button-outline">Withdraw</button></form>@endif
                </div></article>
            @empty<p>No jobs have been posted for these branches.</p>@endforelse
            {{ $items->links() }}
        </section>
    </div>
</x-layouts.site>
