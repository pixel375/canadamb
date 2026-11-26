<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class Announcements
{
    public const MAX_RECIPIENTS = 500;

    public static function enabled(): bool
    {
        return (bool) config('network.announcement_delivery') && BroadcastConsent::enabled()
            && Schema::hasTable('announcements') && Schema::hasTable('announcement_receipts');
    }

    public static function canSend(): bool
    {
        return self::enabled() && ! config('network.prelaunch');
    }

    public static function visible(int $member): Builder
    {
        // History stays available after withdrawal; withdrawal stops future sends.
        return DB::table('announcement_receipts as receipt')->join('announcements as announcement', 'announcement.id', '=', 'receipt.announcement_id')
            ->where('receipt.user_id', $member);
    }

    public static function audienceIds(string $audience, array $members): array
    {
        return BroadcastConsent::audience($audience, $members)->orderBy('users.id')
            ->limit(self::MAX_RECIPIENTS + 1)->pluck('users.id')->map(fn ($id) => (int) $id)->all();
    }
}
