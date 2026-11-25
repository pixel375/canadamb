<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class NotificationPreferences
{
    public static function enabled(): bool
    {
        return (bool) config('network.notification_preferences') && Schema::hasTable('notification_preferences');
    }

    public static function version(array $preferences): string
    {
        return hash('sha256', json_encode($preferences, JSON_THROW_ON_ERROR));
    }

    public static function forMember(int $member): array
    {
        $row = self::enabled() ? DB::table('notification_preferences')->where('user_id', $member)->first() : null;

        return [
            'discussion_replies' => (bool) ($row->discussion_replies ?? true),
            'job_summary' => (bool) ($row->job_summary ?? true),
        ];
    }
}
