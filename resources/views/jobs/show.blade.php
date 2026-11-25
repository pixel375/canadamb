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
<x-layouts.site :title="$item->title" :canonical="$canonical" :structured-data="$jobData" :location="$item->branch->location">
    <div class="container page-shell">
        <a href="{{ \App\Support\LocationScope::browseUrl(request()->attributes->get('site'), '/jobs', $item->branch->location) }}" class="text-link mb-4">Job board</a>
        <header class="panel public-business-hero">
            <span class="public-business-avatar"><x-icon name="briefcase" size="38"/></span>
            <div class="public-business-heading">
                <p class="section-label">{{ $item->branch->business->name }}</p>
                <h1>{{ $item->title }}</h1>
                <p>{{ $item->branch->location->name }} · Posted {{ $item->published_at->diffForHumans() }}</p>
            </div>
        </header>
        <div class="public-profile-layout">
            <div class="public-profile-main">
                <section class="panel">
                    <h2>Job overview</h2>
                    <dl class="job-detail-facts">
                        <div><dt>Employment type</dt><dd>{{ \App\Support\JobBoard::TYPES[$item->employment_type] }}</dd></div>
                        <div><dt>Workplace</dt><dd>{{ \App\Support\JobBoard::WORKPLACES[$item->workplace] }}</dd></div>
                        <div><dt>Pay</dt><dd>{{ $item->payLabel() ? $item->payLabel().' CAD' : 'Not specified' }}</dd></div>
                        <div><dt>Location</dt><dd>{{ $item->branch->location->name }}, Manitoba</dd></div>
                    </dl>
                    <h2 class="listing-detail-subhead">Job description</h2><p class="prose-text">{{ $item->description }}</p>
                </section>
            </div>
            <aside class="public-profile-aside">
                <section class="panel form-stack">
                    <h2>Apply for this job</h2><x-content-report-link type="job" :target="$item->id"/>
                    <p>Applications close <strong>{{ $item->closing_on->format('F j, Y') }}</strong> (Manitoba time).</p>
                    <a href="{{ $item->apply_url }}" rel="nofollow ugc" class="button button-primary">Apply on the employer’s site <x-icon name="arrow" size="16"/></a>
                    <p class="field-note">Applications are handled by the employer, not CanadaMB. Do not pay application fees or share sensitive information before verifying the employer.</p><a class="text-link" href="/contact?topic=business">Raise a concern about this job</a>
                    @auth
                        <form method="post" action="/jobs/{{ $item->id }}/save">@csrf @if($saved) @method('DELETE') @endif
                            <input type="hidden" name="return_to" value="job">
                            <button class="button button-outline seller-action" aria-pressed="{{ $saved ? 'true' : 'false' }}"><x-icon :name="$saved ? 'check' : 'tag'" size="17"/> {{ $saved ? 'Remove saved job' : 'Save job' }}</button>
                        </form>
                    @endauth
                </section>
                @if(\App\Support\MessagingExtensions::enabled() && \App\Support\BusinessInquirySettings::accepts($item->branch->business, true) && auth()->check() && auth()->user()->hasVerifiedEmail() && $item->branch->business->members()->wherePivot('role', 'owner')->exists() && !$item->branch->business->members()->whereKey(auth()->id())->exists())
                    <x-inquiry-form :path="'/jobs/'.$item->id.'/message'" label="Ask about this job"/>
                @endif
                <section class="panel form-stack">
                    <h2>About the employer</h2>
                    <p><strong>{{ $item->branch->business->name }}</strong></p><p>{{ $item->branch->location->name }}</p>
                    @if(app(\App\Support\BusinessDirectory::class)->enabled(request()->attributes->get('site')))
                        <a class="text-link" href="/businesses/{{ $item->branch->business->slug }}">View business profile <x-icon name="arrow" size="16"/></a>
                    @endif
                </section>
            </aside>
        </div>
    </div>
</x-layouts.site>
