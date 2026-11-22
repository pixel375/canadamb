<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class NotificationInbox
{
    public const EVENTS = [
        'listing' => ['listing.approved', 'listing.rejected', 'listing.removed', 'listing.expired'],
        'business' => ['business.approved', 'business.rejected'],
        'claim' => ['claim.approved', 'claim.rejected'],
        'business-update' => ['business-update.approved', 'business-update.rejected'],
        'job' => ['job.approved', 'job.rejected'],
        'thread' => ['thread.approved', 'thread.rejected', 'thread.pending', 'thread.withdrawn'],
        'reply' => ['reply.approved', 'reply.rejected', 'reply.followed'],
        'event' => ['event.approved', 'event.rejected'],
        'message' => ['message.received'],
    ];

    private const OPTIONAL = [
        'reply.followed' => 'notify_discussion_replies',
    ];

    private const MESSAGE_PREFERENCES = [
        'marketplace' => 'notify_marketplace_messages',
        'business' => 'notify_business_messages',
        'job' => 'notify_job_messages',
    ];

    /** Called while the reply is locked in the moderation transaction. */
    public static function recordFollowedReply(int $thread, int $reply, ?int $author, ?int $location): void
    {
        $followers = DB::table('community_thread_follows as follow')->where('follow.thread_id', $thread);
        $recipients = (clone $followers)
            ->when($author !== null, fn (Builder $query) => $query->where('follow.user_id', '!=', $author))
            ->whereNotExists(fn (Builder $query) => $query->selectRaw('1')->from('member_settings as settings')
                ->whereColumn('settings.user_id', 'follow.user_id')->where('settings.notify_discussion_replies', false))
            ->whereNotExists(fn (Builder $query) => $query->selectRaw('1')->from('community_notifications as legacy')
                ->whereColumn('legacy.user_id', 'follow.user_id')->where('legacy.reply_id', $reply))
            ->whereNotExists(fn (Builder $query) => $query->selectRaw('1')->from('notifications as prior')
                ->whereColumn('prior.user_id', 'follow.user_id')->where('prior.event', 'reply.followed')->where('prior.source_id', $reply));

        $created = DB::table('notifications')->insertUsing(['user_id', 'event', 'source_id', 'resource_id', 'created_at'],
            $recipients->select('follow.user_id')->selectRaw('?, ?, ?, ?', ['reply.followed', $reply, $reply, now()]));
        if ($created > 0 && $location !== null) {
            DB::table('notification_locations')->insertUsing(['notification_id', 'location_id'],
                $followers->join('notifications as notice', 'notice.user_id', '=', 'follow.user_id')
                    ->where('notice.event', 'reply.followed')->where('notice.source_id', $reply)
                    ->whereNotExists(fn (Builder $query) => $query->selectRaw('1')->from('notification_locations as scope')
                        ->whereColumn('scope.notification_id', 'notice.id'))
                    ->select('notice.id')->selectRaw('?', [$location]));
        }
    }

    /** Source state changes must enclose this write in their transaction. */
    public static function record(int $recipientId, string $event, int $sourceId, int $resourceId, array $locationIds, string $messageContext = 'marketplace'): void
    {
        $type = explode('.', $event, 2)[0];
        if (! in_array($event, self::EVENTS[$type] ?? [], true)) {
            throw new InvalidArgumentException('Unknown inbox event.');
        }

        $preference = $event === 'message.received'
            ? (self::MESSAGE_PREFERENCES[$messageContext] ?? throw new InvalidArgumentException('Unknown message context.'))
            : (self::OPTIONAL[$event] ?? null);
        if ($preference !== null) {
            if (DB::table('member_settings')->where('user_id', $recipientId)->where($preference, false)->exists()) {
                return;
            }
        }
        if ($event === 'reply.followed' && DB::table('community_notifications')
            ->where('user_id', $recipientId)->where('reply_id', $resourceId)->exists()) {
            return;
        }

        // A retry preserves the original timestamp, coverage and read state.
        try {
            $id = DB::table('notifications')->insertGetId([
                'user_id' => $recipientId, 'event' => $event, 'source_id' => $sourceId,
                'resource_id' => $resourceId, 'created_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return;
        }

        $locations = array_values(array_unique(array_filter($locationIds, fn ($location) => $location !== null)));
        if ($locations !== []) {
            DB::table('notification_locations')->insert(array_map(fn ($location) => [
                'notification_id' => $id, 'location_id' => $location,
            ], $locations));
        }
    }
}
