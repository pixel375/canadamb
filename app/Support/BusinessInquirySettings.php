<?php

namespace App\Support;

use App\Models\Business;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class BusinessInquirySettings
{
    public static function enabled(): bool
    {
        return MessagingExtensions::enabled() && (bool) config('network.business_inquiry_controls') && Schema::hasTable('business_inquiry_settings');
    }

    public static function accepts(Business $business, bool $job): bool
    {
        if (! config('network.business_inquiry_controls')) {
            return true;
        }
        if (! self::enabled()) {
            return false;
        }
        $owners = $business->members()->wherePivot('role', 'owner')->pluck('users.id');
        if ($owners->count() !== 1) {
            return false;
        }

        return (bool) DB::table('business_inquiry_settings')->where('business_id', $business->id)
            ->where('owner_id', $owners->first())->value($job ? 'job_inquiries' : 'business_inquiries');
    }
}
