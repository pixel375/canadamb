<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

final class MessageAttachments
{
    public const DISK = 'message-private';

    public static function enabled(): bool
    {
        return (bool) config('network.message_attachments') && Schema::hasTable('message_attachments');
    }

    public static function safeDirectory(): bool
    {
        $disk = Storage::disk(self::DISK);
        // storage/app itself is a deliberate release-to-shared-storage symlink.
        // The private subtree and attachment directory must not be redirected.
        $root = rtrim($disk->path(''), DIRECTORY_SEPARATOR);
        foreach ([$root, dirname($root), $disk->path('attachments')] as $directory) {
            if (is_link($directory) || (file_exists($directory) && ! is_dir($directory))) {
                return false;
            }
        }

        return true;
    }

    public static function validPath(string $path, bool $previewAllowed = false): bool
    {
        $suffix = $previewAllowed ? '(-preview)?' : '';

        return (bool) preg_match('/^attachments\/[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}'.$suffix.'\.webp$/D', $path);
    }

    public static function readable(string $path): bool
    {
        if (! self::validPath($path) || ! self::safeDirectory()) {
            return false;
        }
        $absolute = Storage::disk(self::DISK)->path($path);

        return is_file($absolute) && ! is_link($absolute);
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
