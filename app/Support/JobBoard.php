<?php

namespace App\Support;

use App\Models\JobPosting;
use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;

class JobBoard
{
    public const TYPES = ['full-time' => 'Full-time', 'part-time' => 'Part-time', 'contract' => 'Contract',
        'seasonal' => 'Seasonal', 'temporary' => 'Temporary', 'internship' => 'Internship'];

    public const WORKPLACES = ['on-site' => 'On-site', 'hybrid' => 'Hybrid', 'remote' => 'Remote'];

    public function enabled(Site $site): bool
    {
        return app(NetworkSettings::class)->forSite($site)['modules']['jobs'] ?? false;
    }

    public function closingToday(): string
    {
        return today('America/Winnipeg')->toDateString();
    }

    public function scope(?int $location): Builder
    {
        return JobPosting::with(['branch.location', 'branch.business'])->when($location,
            fn (Builder $jobs, int $id) => $jobs->whereHas('branch', fn (Builder $branch) => $branch->where('location_id', $id)));
    }

    public function visible(?int $location): Builder
    {
        return $this->scope($location)->where('status', 'approved')->where('closing_on', '>=', $this->closingToday())
            ->whereHas('branch.business', fn (Builder $business) => $business->where('status', 'active'));
    }

    public function canonicalSite(JobPosting $job): Site
    {
        $job->loadMissing('branch.location.site.domain');
        $local = $job->branch->location->site;

        return $local?->status === 'active' && $this->enabled($local) ? $local : NetworkUrl::main();
    }
}
