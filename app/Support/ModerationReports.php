<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class ModerationReports
{
    public const CONTENT_TYPES = [
        'business' => 'Business profiles',
        'business-update' => 'Business updates',
        'job' => 'Jobs',
        'thread' => 'Discussion threads',
        'reply' => 'Discussion replies',
        'event' => 'Events',
    ];

    public const TYPES = ['listing' => 'Marketplace listings'] + self::CONTENT_TYPES + ['conversation' => 'Conversations'];

    public const STATUSES = ['open' => 'Open', 'resolved' => 'Resolved', 'dismissed' => 'Dismissed'];

    public const VERSION_RULE = 'required|string|regex:/\A[a-f0-9]{64}\z/';

    /** The item ID column comes from the admin queue's server-owned queries. */
    public static function openFor(string $type, string $itemIdColumn): Builder
    {
        if ($type === 'listing') {
            return DB::table('listing_reports as flag')->selectRaw('1')
                ->whereColumn('flag.listing_id', $itemIdColumn)->whereNull('flag.resolved_at');
        }

        return DB::table('content_reports as flag')->selectRaw('1')
            ->where('flag.source_type', $type)->whereColumn('flag.source_id', $itemIdColumn)
            ->whereNotExists(fn (Builder $decisions) => $decisions->selectRaw('1')->from('moderation_report_decisions as disposition')
                ->whereColumn('disposition.report_type', 'flag.source_type')->whereColumn('disposition.report_id', 'flag.id'));
    }

    public function queue(): Builder
    {
        $listing = DB::table('listing_reports as report')
            ->join('listings as listing', 'listing.id', '=', 'report.listing_id')
            ->leftJoin('moderation_report_decisions as decision', fn (JoinClause $join) => $join->on('decision.report_id', '=', 'report.id')->where('decision.report_type', 'listing'))
            ->selectRaw("'listing' AS type, report.id, report.listing_id AS source_id, listing.location_id, listing.title, report.created_at AS submitted_at,
                COALESCE(decision.decision, CASE WHEN report.resolved_at IS NULL THEN 'open' ELSE 'resolved' END) AS status");

        // Only report metadata crosses into the queue. No reason, message ID, reporter ID, or conversation content is selected.
        $conversation = DB::table('conversation_reports as report')
            ->join('conversations as thread', 'thread.id', '=', 'report.conversation_id')
            ->leftJoin('listings as listing', 'listing.id', '=', 'thread.listing_id')
            ->leftJoin('moderation_report_decisions as decision', fn (JoinClause $join) => $join->on('decision.report_id', '=', 'report.id')->where('decision.report_type', 'conversation'))
            ->selectRaw("'conversation' AS type, report.id, thread.id AS source_id, COALESCE(thread.location_id, listing.location_id) AS location_id,
                'Conversation report' AS title, report.created_at AS submitted_at, COALESCE(decision.decision, 'open') AS status");

        $content = DB::table('content_reports as report')
            ->leftJoin('businesses as business', fn (JoinClause $join) => $join->on('business.id', '=', 'report.source_id')->where('report.source_type', 'business'))
            ->leftJoin('business_updates as business_update', fn (JoinClause $join) => $join->on('business_update.id', '=', 'report.source_id')->where('report.source_type', 'business-update'))
            ->leftJoin('job_postings as job', fn (JoinClause $join) => $join->on('job.id', '=', 'report.source_id')->where('report.source_type', 'job'))
            ->leftJoin('community_threads as thread', fn (JoinClause $join) => $join->on('thread.id', '=', 'report.source_id')->where('report.source_type', 'thread'))
            ->leftJoin('community_replies as reply', fn (JoinClause $join) => $join->on('reply.id', '=', 'report.source_id')->where('report.source_type', 'reply'))
            ->leftJoin('community_threads as reply_thread', 'reply_thread.id', '=', 'reply.thread_id')
            ->leftJoin('community_events as event', fn (JoinClause $join) => $join->on('event.id', '=', 'report.source_id')->where('report.source_type', 'event'))
            ->leftJoin('moderation_report_decisions as decision', fn (JoinClause $join) => $join->on('decision.report_id', '=', 'report.id')->on('decision.report_type', '=', 'report.source_type'))
            ->selectRaw("report.source_type AS type, report.id, report.source_id, report.location_id,
                COALESCE(business.name, business_update.title, job.title, thread.title, reply_thread.title, event.title) AS title,
                report.created_at AS submitted_at, COALESCE(decision.decision, 'open') AS status");

        return DB::query()->fromSub($listing->unionAll($conversation)->unionAll($content), 'report_queue');
    }

    public function source(string $type, int $id, bool $lock = false): object
    {
        $query = match ($type) {
            'listing' => DB::table('listing_reports'),
            'conversation' => DB::table('conversation_reports'),
            default => DB::table('content_reports')->where('source_type', $type),
        };

        return $query->where('id', $id)->when($lock, fn (Builder $query) => $query->lockForUpdate())->firstOrFail();
    }

    public function decision(string $type, int $id): ?object
    {
        return DB::table('moderation_report_decisions as decision')
            ->leftJoin('users as reviewer', 'reviewer.id', '=', 'decision.reviewer_id')
            ->where('decision.report_type', $type)->where('decision.report_id', $id)
            ->first(['decision.*', 'reviewer.name as reviewer_name']);
    }

    public function legacyDecision(object $report): object
    {
        $entry = DB::table('audit_logs as audit')
            ->leftJoin('users as reviewer', 'reviewer.id', '=', 'audit.actor_id')
            ->where('audit.action', 'listing.report-resolved')->where('audit.context->report', $report->id)
            ->latest('audit.id')->first(['audit.context', 'reviewer.name as reviewer_name']);
        $context = $entry === null ? [] : json_decode($entry->context, true);

        return (object) [
            'note' => $context['note'] ?? null, 'reviewer_name' => $entry->reviewer_name ?? null, 'created_at' => $report->resolved_at,
        ];
    }

    public static function version(string $type, object $report): string
    {
        return hash('sha256', json_encode([
            $type, $report->id, $report->listing_id ?? $report->conversation_id ?? $report->source_id,
            $report->message_id ?? null, $report->created_at, $report->updated_at ?? null,
            $report->resolved_at ?? null,
        ], JSON_THROW_ON_ERROR));
    }

    public function decide(int $actor, string $type, int $id, string $version, string $decision, string $note): void
    {
        try {
            DB::transaction(function () use ($actor, $type, $id, $version, $decision, $note): void {
                $report = $this->source($type, $id, lock: true);
                abort_unless(hash_equals(self::version($type, $report), $version), 409, 'The report changed. Reload it before making a decision.');
                abort_if(isset($report->resolved_at) || DB::table('moderation_report_decisions')
                    ->where(['report_type' => $type, 'report_id' => $id])->lockForUpdate()->first(['id']) !== null,
                    409, 'This report already has a decision.');

                if ($type === 'listing') {
                    DB::table('listing_reports')->where('id', $id)->update(['resolved_at' => now(), 'updated_at' => now()]);
                }
                $action = $type === 'listing' ? 'listing.report-'.$decision : 'moderation.report-'.$decision;
                $audit = Audit::record($action, $actor, context: ['type' => $type, 'report' => $id]);
                DB::table('moderation_report_decisions')->insert([
                    'report_type' => $type, 'report_id' => $id, 'decision' => $decision, 'note' => $note,
                    'reviewer_id' => $actor, 'audit_log_id' => $audit, 'created_at' => now(),
                ]);
            }, attempts: 3);
        } catch (QueryException $error) {
            Log::warning('Report decision write failed.', ['type' => $type, 'report' => $id, 'sqlstate' => $error->errorInfo[0] ?? null]);
            abort(503, 'This report decision is temporarily unavailable.');
        }
    }

    public function evidence(int $actor, int $id): array
    {
        return DB::transaction(function () use ($actor, $id): array {
            $initial = $this->source('conversation', $id);
            $thread = DB::table('conversations as thread')->where('thread.id', $initial->conversation_id)
                ->lockForUpdate()->firstOrFail(['thread.id', 'thread.buyer_id', 'thread.seller_id']);
            abort_if(in_array($actor, [$thread->buyer_id, $thread->seller_id], true), 403);
            $report = $this->source('conversation', $id, lock: true);
            abort_unless($report->conversation_id === $thread->id, 404);
            $authorized = DB::table('conversations as thread')->where('thread.id', $thread->id)
                ->whereRaw(ConversationAccess::memberSql('thread', '?', '?'), [$report->reporter_id, $report->reporter_id])
                ->lockForUpdate()->first(['thread.id']);
            abort_unless($authorized, 404);

            $message = $report->message_id === null ? null : DB::table('messages')
                ->where('conversation_id', $thread->id)->where('sender_id', '!=', $report->reporter_id)
                ->whereIn('sender_id', [$thread->buyer_id, $thread->seller_id])
                ->where('id', $report->message_id)->lockForUpdate()->firstOrFail(['id', 'body', 'created_at']);
            Audit::record($message ? 'moderation.report-evidence-viewed' : 'moderation.report-submission-viewed', $actor, context: [
                'type' => 'conversation', 'report' => $id, 'message' => $message?->id,
            ]);

            return [$report->reason, $message];
        }, attempts: 3);
    }
}
