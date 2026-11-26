<x-layouts.admin title="Job moderation">
    <div class="page-intro"><div><h1>Job moderation</h1><p>Review employer submissions and published jobs.</p></div></div>
    <x-admin-queue-filters path="/admin/jobs" :filters="$filters" :counts="$counts" :statuses="['pending','approved','rejected','expired','withdrawn']" :places="$places" search-label="Search job title or description"/>
    @if($items->contains(fn ($item) => in_array($item->status, ['pending', 'approved'], true)))
        <details class="panel form-stack mb-4">
            <summary>Review selected job postings</summary>
            <form method="post" action="/admin/jobs/selected" id="job-batch-review" class="form-stack mt-3">
                @csrf
                <p class="field-note">Independently review each posting first, then select up to ten eligible postings. A stale, conflicted, closed or otherwise ineligible selection prevents the entire action.</p>
                <label>Review action<select name="action"><option value="approve">Approve pending postings</option><option value="reject">Reject or remove from public view</option></select></label>
                <label>Shared reason (required for rejection or removal)<input name="reason" maxlength="300"></label>
                <label class="checkbox"><input type="checkbox" name="confirm_review" value="1" required> I have independently reviewed every selected posting</label>
                <div><button class="button button-outline">Apply selected job review</button></div>
            </form>
        </details>
    @endif
    <section class="panel">
        @forelse($items as $item)
            <article class="admin-review-card form-stack">
                <div><span class="member-listing-status" data-status="{{ $item->status }}">{{ ucfirst($item->status) }}</span><h3>{{ $item->title }}</h3><p>{{ $item->branch->business->name }} · {{ $item->branch->location->name }} · {{ ucfirst($item->status) }}</p>
                    <p>{{ \App\Support\JobBoard::TYPES[$item->employment_type] }} · {{ \App\Support\JobBoard::WORKPLACES[$item->workplace] }} · Closes {{ $item->closing_on->format('M j, Y') }}@if($item->payLabel()) · {{ $item->payLabel() }} CAD @endif</p>
                    <a class="text-link" rel="noreferrer" href="{{ $item->apply_url }}">{{ $item->apply_url }}</a>
                </div>
                <p class="prose-text">{{ $item->description }}</p>
                @if(in_array($item->status, ['pending', 'approved'], true))
                <label class="checkbox"><input form="job-batch-review" type="checkbox" name="jobs[]" value="{{ $item->id }}"> Select this posting for review</label>
                <input form="job-batch-review" type="hidden" name="versions[{{ $item->id }}]" value="{{ $item->reviewVersion() }}">
                <form method="post" action="/admin/jobs/{{ $item->id }}" class="form-stack">@csrf
                    <input type="hidden" name="version" value="{{ $item->reviewVersion() }}">
                    <label>Reason for rejection<input name="reason" maxlength="300"></label>
                    <div class="inline-actions">
                        @if($item->status === 'pending')<button class="button button-primary" name="action" value="approve">Approve</button>@endif
                        <button class="button button-outline" name="action" value="reject">{{ $item->status === 'approved' ? 'Remove from public view' : 'Reject' }}</button>
                    </div>
                </form>
                @else<p class="field-note">Historical record · no moderation actions available.</p>@endif
            </article>
        @empty<p>No job postings to review.</p>@endforelse
        {{ $items->links() }}
    </section>
</x-layouts.admin>
