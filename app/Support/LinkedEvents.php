<?php

namespace App\Support;

use App\Models\CommunityEvent;
use App\Models\CommunityThread;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

final class LinkedEvents
{
    public static function eligible(CommunityThread $thread): bool
    {
        return $thread->author_id !== null && $thread->status === 'approved' && ! $thread->locked;
    }

    public static function matches(CommunityEvent $event, CommunityThread $thread): bool
    {
        return self::eligible($thread) && $thread->author_id === $event->author_id
            && ($thread->location_id === null || $thread->location_id === $event->location_id);
    }

    /** SQL aliases are server-owned. Provincial sources have no public city-host page. */
    public static function visibleSourceSql(string $event, string $thread, bool $discussions, bool $local): string
    {
        if (! $discussions) {
            return "$event.thread_id IS NULL";
        }
        $location = "$thread.location_id = $event.location_id";
        if (! $local) {
            $location .= " OR $thread.location_id IS NULL";
        }

        return "($event.thread_id IS NULL OR ($thread.id = $event.thread_id AND $thread.status = 'approved'
            AND $thread.locked = 0 AND $thread.author_id = $event.author_id AND ($location)))";
    }

    /** All linked mutations acquire source first and then event. Call in a transaction. */
    public static function lock(int $id): CommunityEvent
    {
        $source = CommunityEvent::whereKey($id)->firstOrFail(['thread_id'])->thread_id;
        $thread = $source === null ? null : CommunityThread::lockForUpdate()->findOrFail($source);
        $event = CommunityEvent::lockForUpdate()->findOrFail($id);
        abort_unless($event->thread_id === $source, 409);
        $event->setRelation('thread', $thread);

        return $event;
    }

    /** Call with the thread row locked before committing any visibility-affecting source change. */
    public static function requeue(CommunityThread $thread, int $actor): void
    {
        $event = CommunityEvent::where('thread_id', $thread->id)->where('status', 'approved')->lockForUpdate()->first();
        if ($event !== null) {
            $event->forceFill(['status' => 'pending'])->save();
            self::record($event, 'source-review', $actor, 'approved');
        }
    }

    public static function record(CommunityEvent $item, string $action, ?int $actor, ?string $before, array $data = []): int
    {
        $context = ['event' => $item->id, 'thread' => $item->thread_id, 'previous_status' => $before, 'status' => $item->status];
        if (isset($data['approval_policy_revision'])) {
            $context['approval_policy_revision'] = $data['approval_policy_revision'];
        }
        $audit = Audit::record('community.event.'.$action, $actor, $data['site_id'] ?? null, $context);
        $changed = $before !== $item->status;
        ModerationDecision::record('event', $item->id, $action, $audit, $data, $changed ? $audit : null);
        if ($changed && $item->author_id !== null) {
            NotificationInbox::record($item->author_id, 'event.'.$item->status, $audit, $item->id, [$item->location_id]);
        }

        return $audit;
    }

    /** Preserve the prior public details once for reports received since the last archive. */
    public static function preserveReportedMetadata(CommunityEvent $item): void
    {
        $lastReport = DB::table('content_reports')->where(['source_type' => 'event', 'source_id' => $item->id])
            ->latest('id')->lockForUpdate()->value('id');
        if ($lastReport === null || self::evidence($item->id, $lastReport)->exists()) {
            return;
        }
        Audit::record('event.report-evidence-preserved', null, context: [
            'event' => $item->id, 'through_report' => $lastReport,
            'snapshot' => Crypt::encryptString(json_encode(self::publicMetadata($item), JSON_THROW_ON_ERROR)),
        ]);
    }

    public static function reportedMetadata(int $event, int $report): ?array
    {
        return DB::transaction(function () use ($event, $report): ?array {
            $item = CommunityEvent::sharedLock()->find($event);
            $snapshot = self::evidence($event, $report)->oldest('id')->value('context->snapshot');
            if ($snapshot !== null) {
                return json_decode(Crypt::decryptString($snapshot), true, flags: JSON_THROW_ON_ERROR);
            }

            return $item === null ? null : self::publicMetadata($item);
        }, attempts: 3);
    }

    private static function evidence(int $event, int $report): Builder
    {
        return DB::table('audit_logs')->where('action', 'event.report-evidence-preserved')
            ->where('context->event', $event)->where('context->through_report', '>=', $report);
    }

    private static function publicMetadata(CommunityEvent $item): array
    {
        return array_intersect_key($item->getRawOriginal(), array_flip(['title', 'body', 'venue', 'organizer', 'category',
            'starts_at', 'ends_at', 'event_url', 'thread_id', 'location_id']));
    }
}
