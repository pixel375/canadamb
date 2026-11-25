<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;

final class BusinessAccess
{
    public static function enabled(): bool
    {
        return (bool) config('network.business_access_requests') && Schema::hasTable('business_access_requests');
    }
}
