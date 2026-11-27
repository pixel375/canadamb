@props(['item', 'removable' => false, 'showDescription' => false])
<article class="item-row"><div>
    <p class="section-label">{{ $item->branch->business->name }} · {{ $item->branch->location->name }}</p>
    <h2><a href="{{ \App\Support\LocationScope::detailUrl('/jobs/'.$item->id) }}">{{ $item->title }}</a></h2>
    <p>{{ \App\Support\JobBoard::TYPES[$item->employment_type] }} · {{ \App\Support\JobBoard::WORKPLACES[$item->workplace] }}@if($item->payLabel()) · {{ $item->payLabel() }} CAD @endif</p>
    @if($showDescription)
        <p class="job-result-description">{{ \Illuminate\Support\Str::limit(\Illuminate\Support\Str::squish(strip_tags($item->description ?? '')), 200) }}</p>
        @if($item->closing_on)<p class="field-note">Apply by {{ $item->closing_on->format('M j, Y') }}</p>@endif
        <a class="text-link mt-2" href="{{ \App\Support\LocationScope::detailUrl('/jobs/'.$item->id) }}">View job <x-icon name="arrow" size="16"/></a>
    @endif
</div>
@if($removable)<form action="/jobs/{{ $item->id }}/save" method="post">@csrf @method('DELETE')<button class="button button-outline">Remove saved job</button></form>@endif
</article>
