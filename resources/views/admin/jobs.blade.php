<x-layouts.admin title="Job moderation">
    <div class="page-intro"><div><h1>Job moderation</h1><p>Review employer submissions and published jobs.</p></div></div>
    <x-admin-queue-filters path="/admin/jobs" :filters="$filters" :counts="$counts" :statuses="['pending','approved','rejected','expired','withdrawn']" :places="$places" search-label="Search job title or description"/>
    <section class="panel">
        @forelse($items as $item)
            <article class="admin-review-card form-stack">
                <div><span class="member-listing-status" data-status="{{ $item->status }}">{{ ucfirst($item->status) }}</span><h3>{{ $item->title }}</h3><p>{{ $item->branch->business->name }} · {{ $item->branch->location->name }} · {{ ucfirst($item->status) }}</p>
                    <p>{{ \App\Support\JobBoard::TYPES[$item->employment_type] }} · {{ \App\Support\JobBoard::WORKPLACES[$item->workplace] }} · Closes {{ $item->closing_on->format('M j, Y') }}@if($item->payLabel()) · {{ $item->payLabel() }} CAD @endif</p>
                    <a class="text-link" rel="noreferrer" href="{{ $item->apply_url }}">{{ $item->apply_url }}</a>
                </div>
                <p class="prose-text">{{ $item->description }}</p>
                @if(in_array($item->status, ['pending', 'approved'], true))
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
