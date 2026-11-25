<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;

final class PrivacyRequests
{
    public static function reviewVersion(object $item): string
    {
        return hash('sha256', json_encode([$item->id, $item->status, $item->reviewer_id, $item->review_note,
            $item->reviewed_at, $item->updated_at], JSON_THROW_ON_ERROR));
    }

    public static function enabled(): bool
    {
        return (bool) config('network.privacy_requests') && Schema::hasTable('account_privacy_requests');
    }
}
