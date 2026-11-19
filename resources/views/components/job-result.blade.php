@props(['item', 'removable' => false])
<article class="item-row"><div>
    <p class="section-label">{{ $item->branch->business->name }} · {{ $item->branch->location->name }}</p>
    <h2><a href="/jobs/{{ $item->id }}">{{ $item->title }}</a></h2>
    <p>{{ \App\Support\JobBoard::TYPES[$item->employment_type] }} · {{ \App\Support\JobBoard::WORKPLACES[$item->workplace] }}@if($item->payLabel()) · {{ $item->payLabel() }} CAD @endif</p>
</div>
@if($removable)<form action="/jobs/{{ $item->id }}/save" method="post">@csrf @method('DELETE')<button class="button button-outline">Remove saved job</button></form>@endif
</article>
