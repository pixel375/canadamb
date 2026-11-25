<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class MessageAttachments
{
    public const DISK = 'message-private';

    public static function enabled(): bool
    {
        return (bool) config('network.message_attachments') && Schema::hasTable('message_attachments');
    }

    public static function quotaExceeded(int $sender, int $conversation, bool $lock = false): bool
    {
        $query = DB::table('message_attachments')->join('messages', 'messages.id', '=', 'message_attachments.message_id');

        $daily = (clone $query)->where('messages.sender_id', $sender)->where('message_attachments.created_at', '>=', now()->subDay());
        $thread = $query->where('messages.conversation_id', $conversation);
        if ($lock) {
            // A locking read avoids an older repeatable-read snapshot after
            // waiting for the participant locks held by a concurrent sender.
            return $daily->lockForUpdate()->limit(20)->pluck('message_attachments.id')->count() >= 20
                || $thread->lockForUpdate()->limit(100)->pluck('message_attachments.id')->count() >= 100;
        }

        return $daily->count() >= 20 || $thread->count() >= 100;
    }
}
