<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class BusinessChanges
{
    public static function enabled(): bool
    {
        return (bool) config('network.business_changes') && Schema::hasTable('business_change_requests');
    }

    public static function invalidateContact(int $business): void
    {
        if (Schema::hasTable('business_change_requests')) {
            DB::table('business_change_requests')->where('business_id', $business)
                ->where('kind', 'contact')->whereIn('status', ['pending', 'approved'])
                ->update(['status' => 'stale', 'updated_at' => now()]);
        }
    }
}
