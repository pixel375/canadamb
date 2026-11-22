<?php

namespace App\Support;

use App\Models\CommunityThread;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

final class DiscussionReportEvidence
{
    /** The caller holds the discussion row lock, which also serializes public reports. */
    public static function preserve(CommunityThread $thread, int $actor): void
    {
        $lastReport = DB::table('content_reports')->where(['source_type' => 'thread', 'source_id' => $thread->id])
            ->latest('id')->lockForUpdate()->value('id');
        if ($lastReport === null) {
            return;
        }
        if (self::captures($thread->id)->where('context->through_report', '>=', $lastReport)->exists()) {
            return;
        }
        Audit::record('discussion.report-evidence-preserved', $actor, context: [
            'thread' => $thread->id,
            'through_report' => $lastReport,
            'snapshot' => Crypt::encryptString(json_encode([
                'title' => $thread->title, 'body' => $thread->body,
            ], JSON_THROW_ON_ERROR)),
        ]);
    }

    public static function forReport(int $thread, int $report): ?array
    {
        return DB::transaction(function () use ($thread, $report): ?array {
            // Read the current text and its archive boundary under the same lock as author edits.
            $current = CommunityThread::sharedLock()->find($thread, ['id', 'title', 'body']);
            $snapshot = self::captures($thread)->where('context->through_report', '>=', $report)
                ->oldest('id')->value('context->snapshot');
            if ($snapshot !== null) {
                return json_decode(Crypt::decryptString($snapshot), true, flags: JSON_THROW_ON_ERROR);
            }

            return $current === null ? null : ['title' => $current->title, 'body' => $current->body];
        }, attempts: 3);
    }

    private static function captures(int $thread): Builder
    {
        return DB::table('audit_logs')->where('action', 'discussion.report-evidence-preserved')
            ->where('context->thread', $thread);
    }
}
