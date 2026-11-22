<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\BusinessUpdate;
use App\Models\CommunityReply;
use App\Models\JobPosting;
use App\Models\Site;
use App\Support\Audit;
use App\Support\BusinessDirectory;
use App\Support\Community;
use App\Support\JobBoard;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ContentReportController extends Controller
{
    public function store(Request $request, string $type, int $target): RedirectResponse
    {
        $data = $request->validate(['reason' => 'required|string|min:8|max:500']);
        $site = $request->attributes->get('site');
        $reporter = $request->user()->id;
        try {
            [$created, $path] = DB::transaction(function () use ($type, $target, $site, $reporter, $data): array {
                [$owner, $location, $path] = match ($type) {
                    'business' => $this->business($site, $target, $reporter),
                    'business-update', 'job' => $this->businessPost($site, $type, $target),
                    'event', 'thread', 'reply' => $this->communityPost($site, $type, $target),
                };
                abort_if($owner === $reporter, 403);

                $key = ['source_type' => $type, 'source_id' => $target, 'reporter_id' => $reporter];
                if (DB::table('content_reports')->where($key)->lockForUpdate()->first(['id']) !== null) {
                    return [false, $path];
                }
                $report = DB::table('content_reports')->insertGetId($key + [
                    'location_id' => $location, 'reason' => $data['reason'], 'created_at' => now(),
                ]);
                Audit::record('moderation.report-submitted', $reporter, $site->id, ['type' => $type, 'report' => $report]);

                return [true, $path];
            }, attempts: 3);
        } catch (QueryException $error) {
            // Driver errors may include private report text in the SQL bindings.
            Log::warning('Content report write failed.', ['type' => $type, 'sqlstate' => $error->errorInfo[0] ?? null]);
            abort(503, 'Reporting is temporarily unavailable.');
        }

        return redirect($path)->with('status', $created ? 'Your report was submitted for review.' : 'You have already reported this content.');
    }

    private function business(Site $site, int $target, int $reporter): array
    {
        $directory = app(BusinessDirectory::class);
        abort_unless($directory->enabled($site), 404);
        $business = $directory->visible($site)->lockForUpdate()->findOrFail($target);
        if ($site->location_id !== null) {
            $business->locations()->where('location_id', $site->location_id)->lockForUpdate()->firstOrFail(['id']);
        }
        abort_if($business->members()->where('users.id', $reporter)->exists(), 403);

        return [null, $site->location_id, '/businesses/'.$business->slug];
    }

    private function businessPost(Site $site, string $type, int $target): array
    {
        $jobs = app(JobBoard::class);
        $directory = app(BusinessDirectory::class);
        $isJob = $type === 'job';
        abort_unless($isJob ? $jobs->enabled($site) : $directory->enabled($site), 404);

        $model = $isJob ? JobPosting::class : BusinessUpdate::class;
        $initial = $model::findOrFail($target, ['id', 'business_location_id']);
        $branch = BusinessLocation::findOrFail($initial->business_location_id, ['id', 'business_id']);
        Business::where('status', 'active')->lockForUpdate()->findOrFail($branch->business_id, ['id']);
        $branch = BusinessLocation::where('business_id', $branch->business_id)
            ->when($site->location_id, fn ($query, $location) => $query->where('location_id', $location))
            ->lockForUpdate()->findOrFail($branch->id, ['id', 'location_id']);
        $public = $isJob ? $jobs->visible($site->location_id) : $directory->updates($site->location_id)->whereNotNull('published_at');
        $item = $public->withoutEagerLoads()->where('business_location_id', $branch->id)->lockForUpdate()->findOrFail($target);
        $path = $isJob ? '/jobs/'.$item->id : '/business-updates/'.$item->id;

        return [$item->author_id, $branch->location_id, $path];
    }

    private function communityPost(Site $site, string $type, int $target): array
    {
        $community = app(Community::class);
        abort_unless($community->enabled($site, $type === 'event' ? 'events' : 'community'), 404);
        if ($type === 'event') {
            $event = $community->events($site->location_id)->withoutEagerLoads()
                ->whereIn('status', ['approved', 'cancelled'])->lockForUpdate()->findOrFail($target);

            return [$event->author_id, $event->location_id, '/events/'.$event->id];
        }

        $threads = $community->threads($site->location_id)->withoutEagerLoads();
        if ($type === 'thread') {
            $thread = $threads->lockForUpdate()->findOrFail($target);

            return [$thread->author_id, $thread->location_id, '/community/'.$thread->id];
        }

        $initial = CommunityReply::where('status', 'approved')->findOrFail($target, ['id', 'thread_id']);
        $thread = $threads->lockForUpdate()->findOrFail($initial->thread_id);
        $reply = $thread->replies()->where('status', 'approved')->lockForUpdate()->findOrFail($target);

        return [$reply->author_id, $thread->location_id, $community->replyPath($thread, $reply->id)];
    }
}
