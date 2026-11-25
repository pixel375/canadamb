<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;

final class PrivacyRequests
{
    public static function enabled(): bool
    {
        return (bool) config('network.privacy_requests') && Schema::hasTable('account_privacy_requests');
    }
}
