<?php

namespace App\Support;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class DirectMessaging
{
    public static function enabled(): bool
    {
        return (bool) config('network.direct_messaging') && MessagingExtensions::enabled()
            && Schema::hasTable('direct_message_preferences') && Schema::hasColumn('conversations', 'direct_kind');
    }

    public static function preferences(int $member): array
    {
        $row = self::enabled() ? DB::table('direct_message_preferences')->where('user_id', $member)->first() : null;

        return ['member_messages' => (bool) ($row->member_messages ?? false), 'official_messages' => (bool) ($row->official_messages ?? false)];
    }

    public static function version(array $preferences): string
    {
        return hash('sha256', json_encode($preferences, JSON_THROW_ON_ERROR));
    }

    public static function allows(User $sender, User $recipient, string $kind): bool
    {
        if (! self::enabled() || $sender->id === $recipient->id || ! $sender->hasVerifiedEmail() || ! $recipient->hasVerifiedEmail()) {
            return false;
        }
        if ($kind === 'official' && $sender->role !== 'super_admin') {
            return false;
        }
        if (! in_array($kind, ['member', 'official'], true)) {
            return false;
        }

        return self::preferences($recipient->id)[$kind === 'official' ? 'official_messages' : 'member_messages'];
    }

    public static function canReply(Conversation $thread): bool
    {
        if (! self::enabled() || $thread->listing_id || $thread->inquiry_business_id || $thread->inquiry_job_id) {
            return false;
        }
        $sender = User::find($thread->buyer_id);
        $recipient = User::find($thread->seller_id);

        return $sender && $recipient && self::allows($sender, $recipient, $thread->direct_kind);
    }

    public static function key(int $sender, int $recipient, string $kind): string
    {
        // Member threads are unique per unordered pair; official threads
        // retain the initiating administrator's identity and separate context.
        if ($kind === 'member' && $sender > $recipient) {
            [$sender, $recipient] = [$recipient, $sender];
        }

        return hash('sha256', implode(':', ['direct', $kind, $sender, $recipient]));
    }
}
