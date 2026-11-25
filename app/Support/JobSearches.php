<?php

namespace App\Support;

use App\Models\JobSearch;
use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

final class JobSearches
{
    public static function enabled(Site $site): bool
    {
        return (bool) config('network.job_searches') && app(JobBoard::class)->enabled($site) && Schema::hasTable('job_searches');
    }

    public static function alertingSearchCount(int $member, Site $site): int
    {
        if (! self::enabled($site)) {
            return 0;
        }

        return JobSearch::where('user_id', $member)->where('site_id', $site->id)->where('alerts', true)
            ->limit(10)->get()->filter(fn (JobSearch $search) => self::newMatches($search, $site)->exists())->count();
    }

    public static function matching(JobSearch $search, Site $site): Builder
    {
        abort_unless((int) $search->site_id === $site->id, 404);
        $board = app(JobBoard::class);

        return $board->filter($board->visible($site->location_id), $site, $search->filters);
    }

    public static function newMatches(JobSearch $search, Site $site): Builder
    {
        return self::matching($search, $site)->where('published_at', '>', $search->checked_at);
    }
}
