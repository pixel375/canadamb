<?php

namespace App\Support;

use App\Models\CommunityReply;
use App\Models\CommunityThread;
use Illuminate\Support\Facades\DB;

final class ReplyPublication
{
    // Caller must hold the parent thread lock inside a transaction.
    public static function approve(CommunityThread $thread, CommunityReply $item): void
    {
        abort_unless($thread->status === 'approved' && $item->status === 'pending', 409);
        $thread->forceFill(['last_activity_at' => now()])->save();
        DB::table('community_notifications')->insertUsing(['user_id', 'reply_id', 'created_at'],
            DB::table('community_thread_follows')->where('thread_id', $thread->id)
                ->when($item->author_id, fn ($query, $author) => $query->where('user_id', '!=', $author))
                ->when(NotificationPreferences::enabled(), fn ($query) => $query->whereNotExists(fn ($preferences) => $preferences->selectRaw('1')->from('notification_preferences')
                    ->whereColumn('notification_preferences.user_id', 'community_thread_follows.user_id')->where('discussion_replies', false)))
                ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('community_notifications')
                    ->whereColumn('community_notifications.user_id', 'community_thread_follows.user_id')->where('reply_id', $item->id))
                ->select('user_id')->selectRaw('?, ?', [$item->id, now()]),
        );
        $parent = ReplyLinks::approvedParentAuthor($thread, $item);
        if ($parent && $parent !== $item->author_id && NotificationPreferences::forMember($parent)['discussion_replies']) {
            DB::table('community_notifications')->insertOrIgnore(['user_id' => $parent, 'reply_id' => $item->id, 'created_at' => now()]);
        }
        $organizer = EventDiscussions::approvedEventAuthor($thread);
        if ($organizer && $organizer !== $item->author_id && NotificationPreferences::forMember($organizer)['discussion_replies']) {
            DB::table('community_notifications')->insertOrIgnore(['user_id' => $organizer, 'reply_id' => $item->id, 'created_at' => now()]);
        }
        $item->forceFill(['status' => 'approved'])->save();
    }
}
