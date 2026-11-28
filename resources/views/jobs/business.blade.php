<x-layouts.site :title="$employer->name.' jobs'">
    @php($returnFilters = array_filter($filters, fn ($value) => $value !== null && $value !== ''))
    @php($editQuery = $returnFilters ? '?'.http_build_query(['return_filters' => $returnFilters], '', '&', PHP_QUERY_RFC3986) : '')
    <div class="container page-shell">
        <div class="page-intro"><div><a href="/account/businesses/{{ $employer->id }}" class="text-link">Business dashboard</a><h1>{{ $employer->name }} jobs</h1><p>Manage hiring across your business branches.</p></div></div>
        <nav class="seller-status-grid" aria-label="Filter jobs by status">
            @foreach(array_merge(['all'], $statuses) as $status)
                <?php $selected = ($filters['status'] ?? 'all') === $status; $query = http_build_query(array_filter(array_replace($filters, ['status' => $status === 'all' ? null : $status]), fn ($value) => $value !== null && $value !== '')); ?>
                <a class="seller-status-tile" href="/account/businesses/{{ $employer->id }}/jobs{{ $query ? '?'.$query : '' }}" @if($selected) aria-current="page" @endif>
                    <strong>{{ $status === 'all' ? $counts->sum() : ($counts[$status] ?? 0) }}</strong><span>{{ $status === 'all' ? 'All jobs' : ucfirst($status) }}</span>
                </a>
            @endforeach
        </nav>
        @if($openBranches->isNotEmpty())
            <details class="panel job-posting-editor" @if($errors->any()) open @endif><summary><x-icon name="plus" size="18"/> Post a job</summary>
                <form method="post" action="/account/businesses/{{ $employer->id }}/jobs" class="form-stack mt-3">@csrf
                    <x-shared-publication-note/>
                    <p class="field-note">Provide a working HTTPS application link.</p>
                    <label>Business branch<select name="business_location_id" required>
                        @foreach($openBranches as $branch)<option value="{{ $branch->id }}" @selected((string) old('business_location_id') === (string) $branch->id)>{{ $branch->location->name }} · {{ $branch->address }}</option>@endforeach
                    </select></label>
                    <x-job-fields/>
                    <div><button class="button button-primary">Submit for review</button></div>
                </form>
            </details>
        @else <p class="notice">This business has no open branches in the current portal.</p> @endif
        <form class="panel saved-search" method="get" action="/account/businesses/{{ $employer->id }}/jobs">
            @if($filters['status'] ?? null)<input type="hidden" name="status" value="{{ $filters['status'] }}">@endif
            <label for="job-owner-search">Search your jobs<input type="search" id="job-owner-search" name="q" maxlength="100" value="{{ $filters['q'] ?? '' }}" placeholder="Search your job postings"></label>
            <label>Branch<select name="branch"><option value="">All branches</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected((int) ($filters['branch'] ?? 0) === $branch->id)>{{ $branch->location->name }} · {{ $branch->address }}</option>@endforeach</select></label>
            <label>Employment type<select name="type"><option value="">All types</option>@foreach(\App\Support\JobBoard::TYPES as $key => $label)<option value="{{ $key }}" @selected(($filters['type'] ?? '') === $key)>{{ $label }}</option>@endforeach</select></label>
            <label>Workplace<select name="workplace"><option value="">All workplaces</option>@foreach(\App\Support\JobBoard::WORKPLACES as $key => $label)<option value="{{ $key }}" @selected(($filters['workplace'] ?? '') === $key)>{{ $label }}</option>@endforeach</select></label>
            <label>Closing date<select name="closing"><option value="">All deadlines</option>@foreach(['week' => 'Next 7 days','month' => 'Next 30 days','past' => 'Past deadline'] as $key => $label)<option value="{{ $key }}" @selected(($filters['closing'] ?? '') === $key)>{{ $label }}</option>@endforeach</select></label>
            <label>Sort<select name="sort">@foreach(['recent' => 'Newest posting','deadline' => 'Earliest closing date','oldest' => 'Oldest posting'] as $key => $label)<option value="{{ $key }}" @selected(($filters['sort'] ?? 'recent') === $key)>{{ $label }}</option>@endforeach</select></label>
            <label>Reference<input type="number" name="reference" min="1" value="{{ $filters['reference'] ?? '' }}"></label>
            <button class="button button-primary"><x-icon name="search" size="16"/> Search</button><a class="text-link" href="/account/businesses/{{ $employer->id }}/jobs">Reset</a>
        </form>
        <p class="field-note seller-results-count">{{ $items->total() }} {{ \Illuminate\Support\Str::plural('job', $items->total()) }} found</p>
        @if($items->contains(fn ($item) => in_array($item->status, ['pending', 'approved'], true)))
            <details class="panel form-stack mb-4"><summary>Withdraw selected postings</summary>
                <form id="job-batch-withdraw" method="post" action="/account/businesses/{{ $employer->id }}/jobs/withdraw-selected" class="form-stack mt-3">@csrf
                    @foreach($returnFilters as $key => $value)<input type="hidden" name="return_filters[{{ $key }}]" value="{{ $value }}">@endforeach
                    <p class="field-note">Choose up to ten pending or approved jobs below. Existing messages and posting history are preserved.</p>
                    <label class="checkbox-line"><input type="checkbox" name="confirm_withdraw" value="1" required> Confirm withdrawal of selected postings</label>
                    <button class="button button-outline">Withdraw selected jobs</button>
                </form>
            </details>
        @endif
        @if($items->contains(fn ($item) => $item->branch->isOpen() && in_array($item->status, ['pending', 'approved', 'expired'], true)))
            <details class="panel form-stack mb-4"><summary>Extend selected closing dates</summary>
                <form id="job-batch-extend" method="post" action="/account/businesses/{{ $employer->id }}/jobs/extend-selected" class="form-stack mt-3">@csrf
                    @foreach($returnFilters as $key => $value)<input type="hidden" name="return_filters[{{ $key }}]" value="{{ $value }}">@endforeach
                    <p class="field-note">Select up to ten eligible postings. The new closing date must be later than every selected deadline. Changed postings require review before publication.</p>
                    <label>New closing date<input type="date" name="new_closing_on" required min="{{ today('America/Winnipeg')->toDateString() }}" max="{{ today('America/Winnipeg')->addYear()->toDateString() }}"></label>
                    <label class="checkbox-line"><input type="checkbox" name="confirm_extend" value="1" required> Confirm deadline changes</label>
                    <button class="button button-outline">Extend selected deadlines</button>
                </form>
            </details>
        @endif
        <section class="seller-listing-grid" aria-label="Your job postings">
            @forelse($items as $item)
                <article class="panel seller-listing-card">
                    <div class="member-listing-info">
                        <span class="member-listing-status" data-status="{{ $item->status }}">{{ ucfirst($item->status) }}</span>
                        <h2>{{ $item->title }}</h2><p>{{ $item->branch->location->name }} @if(!$item->branch->isOpen()) (closed branch) @endif · {{ \App\Support\JobBoard::TYPES[$item->employment_type] ?? $item->employment_type }} · {{ \App\Support\JobBoard::WORKPLACES[$item->workplace] ?? $item->workplace }}</p>
                        @if($item->payLabel())<p>{{ $item->payLabel() }}</p>@endif
                        <p>Closes {{ $item->closing_on->format('M j, Y') }}</p><p class="field-note">Reference #{{ $item->id }}</p>
                    </div>
                    <p class="seller-lifecycle-note">
                        @switch($item->status)
                            @case('approved') {{ $item->closing_on->toDateString() >= today('America/Winnipeg')->toDateString() ? 'Published and accepting applications.' : 'Closing date passed. Awaiting automatic expiry.' }} @break
                            @case('pending') Awaiting moderator approval. @break
                            @case('rejected') Not approved. Edit and submit again for review. @break
                            @case('expired') This posting has closed and is no longer public. @break
                            @case('withdrawn') This posting was withdrawn and is no longer public. @break
                        @endswitch
                    </p>
                    <div class="inline-actions seller-listing-actions">
                        @if($item->branch->isOpen() && $item->status === 'approved' && $item->closing_on->toDateString() >= today('America/Winnipeg')->toDateString())<a class="button button-outline" href="/jobs/{{ $item->id }}">View posting</a>@endif
                        @if($item->branch->isOpen() && in_array($item->status, ['pending', 'approved', 'rejected', 'expired'], true))<a class="button button-outline" href="/account/businesses/{{ $employer->id }}/jobs/{{ $item->id }}/edit{{ $editQuery }}">Edit</a>@endif
                        @if($openBranches->isNotEmpty())<a class="button button-outline" href="/account/businesses/{{ $employer->id }}/jobs/{{ $item->id }}/duplicate">Reuse posting</a>@endif
                    </div>
                    @if($item->branch->isOpen() && in_array($item->status, ['pending', 'approved', 'expired'], true))
                        <label class="checkbox-line"><input form="job-batch-extend" type="checkbox" name="jobs[]" value="{{ $item->id }}"> Select to extend deadline</label>
                        <input form="job-batch-extend" type="hidden" name="versions[{{ $item->id }}]" value="{{ $item->reviewVersion() }}">
                    @endif
                    @if(in_array($item->status, ['pending', 'approved'], true))
                        <label class="checkbox-line"><input form="job-batch-withdraw" type="checkbox" name="jobs[]" value="{{ $item->id }}"> Select for withdrawal</label>
                        <input form="job-batch-withdraw" type="hidden" name="versions[{{ $item->id }}]" value="{{ $item->reviewVersion() }}">
                        <details class="seller-remove-listing"><summary>Withdraw posting</summary><p class="field-note">Withdraw this job from publication and review.</p><form method="post" action="/account/businesses/{{ $employer->id }}/jobs/{{ $item->id }}/withdraw">@csrf @foreach($returnFilters as $key => $value)<input type="hidden" name="return_filters[{{ $key }}]" value="{{ $value }}">@endforeach<input type="hidden" name="version" value="{{ $item->reviewVersion() }}"><button class="button button-outline">Confirm withdrawal</button></form></details>
                    @endif
                </article>
            @empty<div class="panel market-results-empty"><x-icon name="briefcase" size="30"/><h2>{{ array_filter($filters, fn ($value) => $value !== null && $value !== '') ? 'No jobs match these filters.' : 'No jobs have been posted for these branches.' }}</h2><a class="button button-outline" href="/account/businesses/{{ $employer->id }}/jobs">View all jobs</a></div>@endforelse
        </section>
        {{ $items->links() }}
    </div>
</x-layouts.site>
