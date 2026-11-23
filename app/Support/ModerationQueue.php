<?php

namespace App\Support;

use App\Models\Business;
use App\Models\BusinessUpdate;
use App\Models\CommunityEvent;
use App\Models\CommunityReply;
use App\Models\CommunityThread;
use App\Models\JobPosting;
use App\Models\Listing;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

final class ModerationQueue
{
    public const TYPES = [
        'listing' => 'Marketplace listings',
        'business' => 'Business profiles',
        'claim' => 'Business claims',
        'business-update' => 'Business updates',
        'job' => 'Jobs',
        'thread' => 'Discussions',
        'reply' => 'Discussion replies',
        'event' => 'Events',
    ];

    public static function find(string $type, int $id): Model|stdClass|null
    {
        return match ($type) {
            'listing' => Listing::with(['location', 'seller', 'media', 'categoryRecord'])->find($id),
            'business' => Business::with('locations.location')->find($id),
            'claim' => DB::table('business_claims')->where('id', $id)->first(),
            'business-update' => BusinessUpdate::with('branch.location', 'branch.business')->find($id),
            'job' => JobPosting::with('branch.location', 'branch.business')->find($id),
            'thread' => CommunityThread::with(['author', 'location'])->find($id),
            'reply' => CommunityReply::with(['thread.location', 'author'])->find($id),
            'event' => CommunityEvent::with(['location', 'thread'])->find($id),
            default => null,
        };
    }

    public function items(array $filters): Builder
    {
        $types = isset($filters['type']) ? [$filters['type']] : array_keys(self::TYPES);
        $branches = array_map(fn (string $type) => $this->source($type, $filters), $types);
        $combined = array_shift($branches);
        foreach ($branches as $branch) {
            $combined->unionAll($branch);
        }

        return DB::query()->fromSub($combined, 'moderation_queue');
    }

    public function counts(array $filters): array
    {
        unset($filters['status'], $filters['type']);

        return $this->items($filters)->select('type')
            ->selectRaw("SUM(CASE WHEN source_status = 'pending' THEN 1 ELSE 0 END) as pending")
            ->selectRaw("SUM(CASE WHEN source_status NOT IN ('pending', 'draft') THEN 1 ELSE 0 END) as reviewed")
            ->selectRaw('SUM(reported) as reported')->groupBy('type')->get()->keyBy('type')->all();
    }

    public function history(string $type, int $id): Builder
    {
        [$actions, $key] = match ($type) {
            'listing' => [['listing.active', 'listing.rejected', 'listing.removed'], 'listing'],
            'business' => [['business.approved', 'business.rejected'], 'business'],
            'claim' => [['business.claim-approved', 'business.claim-rejected'], 'claim'],
            'business-update' => [['business.update-approved', 'business.update-rejected'], 'update'],
            'job' => [['jobs.approve', 'jobs.reject'], 'job'],
            'thread' => [[
                'community.thread.approve', 'community.thread.reject', 'community.thread.pin', 'community.thread.unpin',
                'community.thread.lock', 'community.thread.unlock', 'community.thread.author-edit', 'community.thread.author-withdraw',
                'community.thread.author-restore',
            ], 'thread'],
            'reply' => [['community.reply.approve', 'community.reply.reject'], 'reply'],
            'event' => [['community.event.approve', 'community.event.reject', 'community.event.edit', 'community.event.cancel',
                'community.event.source-review', 'community.event.request', 'community.event.auto-approve',
                'community.event.author-edit', 'community.event.author-cancel', 'community.event.author-resubmit'], 'event'],
        };

        return DB::table('audit_logs as audit')->leftJoin('users as reviewer', 'reviewer.id', '=', 'audit.actor_id')
            ->leftJoin('moderation_decisions as decision', 'decision.audit_id', '=', 'audit.id')
            ->whereIn('audit.action', $actions)->where("audit.context->$key", $id)->latest('audit.id')
            ->select('audit.id', 'audit.action', 'audit.created_at', 'audit.context', 'reviewer.name', 'decision.reason', 'decision.notes');
    }

    private function source(string $type, array $filters): Builder
    {
        $table = match ($type) {
            'listing' => 'listings', 'business' => 'businesses', 'claim' => 'business_claims',
            'business-update' => 'business_updates', 'job' => 'job_postings', 'thread' => 'community_threads',
            'reply' => 'community_replies', 'event' => 'community_events',
        };
        $query = DB::table("$table as item");
        $title = match ($type) {
            'business' => 'item.name', 'claim' => 'business.name', 'reply' => 'thread.title', default => 'item.title',
        };
        $author = match ($type) {
            'business' => null, 'listing', 'claim' => 'item.user_id', default => 'item.author_id',
        };
        $location = match ($type) {
            'business', 'claim' => null, 'business-update', 'job' => 'branch.location_id',
            'reply' => 'thread.location_id', default => 'item.location_id',
        };
        match ($type) {
            'claim' => $query->join('businesses as business', 'business.id', '=', 'item.business_id'),
            'reply' => $query->join('community_threads as thread', 'thread.id', '=', 'item.thread_id'),
            'business-update', 'job' => $query->join('business_locations as branch', 'branch.id', '=', 'item.business_location_id'),
            default => null,
        };
        if ($location !== null) {
            $query->leftJoin('locations as place', 'place.id', '=', $location);
        }
        if ($author !== null) {
            $query->leftJoin('users as author', 'author.id', '=', $author);
        }

        $reported = ModerationReports::openFor($type, 'item.id');
        $query->selectRaw('? as type, '.$title.' as title', [$type])->addSelect('item.id', 'item.status as source_status', 'item.created_at', 'item.updated_at')
            ->selectRaw(($author === null ? 'NULL' : 'author.name').' as submitter')
            ->selectRaw(($location === null ? 'NULL' : 'place.name').' as location')
            ->selectRaw('CASE WHEN EXISTS ('.$reported->toSql().') THEN 1 ELSE 0 END as reported', $reported->getBindings());

        if (isset($filters['location'])) {
            if ($location !== null) {
                $query->where($location, $filters['location']);
            } else {
                $query->whereExists(fn (Builder $branches) => $branches->selectRaw('1')->from('business_locations as coverage')
                    ->whereColumn('coverage.business_id', $type === 'business' ? 'item.id' : 'item.business_id')
                    ->where('coverage.location_id', $filters['location']));
            }
        }
        if (isset($filters['q'])) {
            $keyword = strtr($filters['q'], ['!' => '!!', '%' => '!%', '_' => '!_']);
            $query->whereRaw("$title LIKE ? ESCAPE '!'", ["%$keyword%"]);
        }
        if (isset($filters['from'])) {
            $query->where('item.created_at', '>=', $this->date($filters['from']));
        }
        if (isset($filters['to'])) {
            $query->where('item.created_at', '<', $this->date($filters['to'], true));
        }

        return match ($filters['status'] ?? 'all') {
            'pending' => $query->where('item.status', 'pending'),
            'reviewed' => $query->whereNotIn('item.status', ['pending', 'draft']),
            'reported' => $query->whereExists($reported),
            default => $query,
        };
    }

    private function date(string $value, bool $nextDay = false): CarbonImmutable
    {
        return CarbonImmutable::parse($value, 'America/Winnipeg')->startOfDay()->addDays((int) $nextDay)->utc();
    }
}
