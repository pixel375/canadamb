<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class MessageDocuments
{
    public const MAX_BYTES = 60_000;

    public static function enabled(): bool
    {
        return (bool) config('network.message_documents') && Schema::hasTable('message_documents');
    }

    public static function inspect(UploadedFile $file): array
    {
        $content = $file->isValid() && strtolower($file->getClientOriginalExtension()) === 'txt'
            && $file->getSize() <= self::MAX_BYTES && $file->getMimeType() === 'text/plain'
            ? file_get_contents($file->getPathname(), false, null, 0, self::MAX_BYTES + 1) : false;
        // Reject binary files, control characters and Unicode directional
        // controls. Never interpret the bytes as HTML, script or a document format.
        if (! is_string($content) || $content === '' || strlen($content) > self::MAX_BYTES || ! mb_check_encoding($content, 'UTF-8')
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{0080}-\x{009F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', $content)
            || preg_match('/<\?(?:php|=)|<\s*(?:script|html|svg|iframe|object)\b|<!doctype\s+html/i', $content)) {
            throw ValidationException::withMessages(['document' => 'Upload a plain UTF-8 text file (.txt), up to 60 KB, without executable or markup content.']);
        }

        return ['content' => $content, 'bytes' => strlen($content), 'sha256' => hash('sha256', $content), 'created_at' => now()];
    }

    public static function quotaExceeded(int $sender, int $conversation): bool
    {
        // Caller holds the same ordered participant locks used for replies.
        $query = DB::table('message_documents')->join('messages', 'messages.id', '=', 'message_documents.message_id');
        $daily = (clone $query)->where('messages.sender_id', $sender)->where('message_documents.created_at', '>=', now()->subDay());
        $thread = $query->where('messages.conversation_id', $conversation);

        return $daily->lockForUpdate()->limit(10)->pluck('message_documents.id')->count() >= 10
            || $thread->lockForUpdate()->limit(50)->pluck('message_documents.id')->count() >= 50;
    }
}
