@php
    $type = match ($item->employment_type) {
        'full-time' => 'FULL_TIME', 'part-time' => 'PART_TIME', 'contract' => 'CONTRACTOR',
        'temporary', 'seasonal' => 'TEMPORARY', 'internship' => 'INTERN', default => 'OTHER',
    };
    $jobData = [
        '@context' => 'https://schema.org', '@type' => 'JobPosting', 'title' => $item->title,
        'description' => $item->description, 'url' => $canonical, 'datePosted' => $item->published_at->toDateString(),
        'validThrough' => $item->closing_on->endOfDay()->toAtomString(), 'employmentType' => $type,
        'hiringOrganization' => ['@type' => 'Organization', 'name' => $item->branch->business->name],
        'jobLocation' => ['@type' => 'Place', 'address' => ['@type' => 'PostalAddress', 'addressLocality' => $item->branch->location->name, 'addressRegion' => 'MB', 'addressCountry' => 'CA']],
    ];
    if ($item->pay_unit) {
        $jobData['baseSalary'] = ['@type' => 'MonetaryAmount', 'currency' => 'CAD', 'value' => [
            '@type' => 'QuantitativeValue', 'minValue' => $item->pay_min / 100, 'maxValue' => $item->pay_max / 100, 'unitText' => strtoupper($item->pay_unit),
        ]];
    }
@endphp
<x-layouts.site :title="$item->title" :canonical="$canonical" :structured-data="$jobData">
    <div class="container page-shell narrow">
        <a href="/jobs" class="text-link mb-4">Job board</a>
        <article class="panel">
            <p class="section-label">{{ $item->branch->business->name }} · {{ $item->branch->location->name }}</p>
            <h1>{{ $item->title }}</h1>
            <p>{{ \App\Support\JobBoard::TYPES[$item->employment_type] }} · {{ \App\Support\JobBoard::WORKPLACES[$item->workplace] }}@if($item->payLabel()) · {{ $item->payLabel() }} CAD @endif</p>
            <p>Applications close {{ $item->closing_on->format('F j, Y') }} (Manitoba time).</p>
            <p class="prose-text">{{ $item->description }}</p>
            <div class="inline-actions">
                <a href="{{ $item->apply_url }}" rel="nofollow ugc" class="button button-primary">Apply on the employer’s site</a>
                @auth
                    <form method="post" action="/jobs/{{ $item->id }}/save">@csrf @if($saved) @method('DELETE') @endif
                        <button class="button button-outline">{{ $saved ? 'Remove saved job' : 'Save job' }}</button>
                    </form>
                @endauth
            </div>
        </article>
    </div>
</x-layouts.site>
