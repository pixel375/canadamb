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

    public static function quotaExceeded(int $sender, int $conversation): bool
    {
        $query = DB::table('message_attachments')->join('messages', 'messages.id', '=', 'message_attachments.message_id');

        return (clone $query)->where('messages.sender_id', $sender)->where('message_attachments.created_at', '>=', now()->subDay())->count() >= 20
            || $query->where('messages.conversation_id', $conversation)->count() >= 100;
    }
}
