<?php

namespace App\Support;

use App\Models\JobPosting;
use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

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

    public static function searchRules(): array
    {
        return [
            'q' => 'nullable|string|max:100', 'type' => ['nullable', Rule::in(array_keys(self::TYPES))],
            'workplace' => ['nullable', Rule::in(array_keys(self::WORKPLACES))], 'location' => 'nullable|string|max:150',
            'pay_unit' => 'required_with:minimum_pay|nullable|in:hour,year', 'minimum_pay' => 'nullable|numeric|decimal:0,2|min:0|max:10000000',
        ];
    }

    public function filter(Builder $query, Site $site, array $filters): Builder
    {
        return $query
            ->when($filters['q'] ?? null, fn ($query, $term) => KeywordSearch::apply($query, $term, ['title', 'description']))
            ->when($filters['type'] ?? null, fn ($query, $type) => $query->where('employment_type', $type))
            ->when($filters['workplace'] ?? null, fn ($query, $workplace) => $query->where('workplace', $workplace))
            ->when($filters['pay_unit'] ?? null, fn ($query, $unit) => $query->where('pay_unit', $unit))
            ->when(isset($filters['minimum_pay']), fn ($query) => $query->where('pay_max', '>=', (int) round($filters['minimum_pay'] * 100)))
            ->when($site->isNetwork() && filled($filters['location'] ?? null), function ($query) use ($filters) {
                $query->whereHas('branch.location', fn ($location) => $location->whereLike('name', '%'.$filters['location'].'%'));
            });
    }

    public function canonicalSite(JobPosting $job): Site
    {
        $job->loadMissing('branch.location.site.domain');
        $local = $job->branch->location->site;

        return $local?->status === 'active' && $this->enabled($local) ? $local : NetworkUrl::main();
    }
}
