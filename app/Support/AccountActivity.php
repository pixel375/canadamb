<?php

namespace App\Support;

use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Read-only account navigation counts. Private messages span the network;
 * approved discussion replies respect the individual portal's scope.
 */
final class AccountActivity
{
    /** @return array{messages:int, notifications:int} */
    public function counts(User $user, Site $site): array
    {
        $member = $user->id;
        $messages = DB::table('messages as message')
            ->join('conversations as conversation', 'conversation.id', '=', 'message.conversation_id')
            ->where(fn (Builder $query) => $query->where('conversation.buyer_id', $member)
                ->orWhere('conversation.seller_id', $member))
            ->where('message.sender_id', '!=', $member)
            ->whereRaw('message.id > CASE WHEN conversation.buyer_id = ? THEN conversation.buyer_last_read_id ELSE conversation.seller_last_read_id END', [$member])
            ->count();

        $replies = app(Community::class)->enabled($site, 'community')
            ? $this->discussionNotifications($user, $site)->whereNull('notification.read_at')->count()
            : 0;

        return ['messages' => $messages, 'notifications' => $replies];
    }

    public function discussionNotifications(User $user, Site $site): Builder
    {
        return DB::table('community_notifications as notification')
            ->join('community_replies as reply', 'reply.id', '=', 'notification.reply_id')
            ->join('community_threads as thread', 'thread.id', '=', 'reply.thread_id')
            ->where('notification.user_id', $user->id)
            ->where('reply.status', 'approved')
            ->where('thread.status', 'approved')
            ->when($site->location_id !== null, fn (Builder $query) => $query->where('thread.location_id', $site->location_id));
    }
}
