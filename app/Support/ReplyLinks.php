<?php

namespace App\Support;

use App\Models\CommunityReply;
use App\Models\CommunityThread;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ReplyLinks
{
    public static function enabled(): bool
    {
        return (bool) config('network.reply_links') && Schema::hasTable('community_reply_links');
    }

    /** Caller holds the thread lock; immutable edge to an earlier approved reply. */
    public static function attach(CommunityReply $reply, CommunityReply $parent): void
    {
        abort_unless(self::enabled() && $reply->thread_id === $parent->thread_id
            && $parent->status === 'approved' && $parent->id < $reply->id, 409);
        DB::table('community_reply_links')->insert(['reply_id' => $reply->id, 'parent_reply_id' => $parent->id]);
    }

    private static function visibleLinks()
    {
        return DB::table('community_reply_links as links')
            ->join('community_replies as child', 'child.id', '=', 'links.reply_id')
            ->join('community_replies as parent', 'parent.id', '=', 'links.parent_reply_id')
            ->whereColumn('child.thread_id', 'parent.thread_id')
            ->whereColumn('parent.id', '<', 'child.id')
            ->where('parent.status', 'approved');
    }

    public static function publicParents(CommunityThread $thread, array $ids): array
    {
        if (! self::enabled() || $ids === []) {
            return [];
        }

        return self::visibleLinks()->where('child.thread_id', $thread->id)->where('child.status', 'approved')
            ->whereIn('child.id', $ids)->leftJoin('users as authors', 'authors.id', '=', 'parent.author_id')
            ->get(['child.id as child_id', 'parent.id', 'parent.body', 'authors.name as author_name'])->keyBy('child_id')->all();
    }

    public static function approvedParentAuthor(CommunityThread $thread, CommunityReply $reply): ?int
    {
        if (! self::enabled()) {
            return null;
        }

        $author = self::visibleLinks()->where('child.thread_id', $thread->id)->where('child.id', $reply->id)->value('parent.author_id');

        return $author === null ? null : (int) $author;
    }

    public static function directNotificationIds(int $member, array $ids): array
    {
        if (! self::enabled() || $ids === []) {
            return [];
        }

        return self::visibleLinks()->where('child.status', 'approved')->where('parent.author_id', $member)
            ->whereIn('child.id', $ids)->pluck('child.id')->map(fn ($id) => (int) $id)->all();
    }
}
