<?php

namespace App\Support;

use App\Models\Business;
use App\Models\Conversation;
use App\Models\JobPosting;
use Illuminate\Support\Facades\Schema;

final class MessagingExtensions
{
    public static function enabled(): bool
    {
        return (bool) config('network.extended_messaging') && Schema::hasColumn('conversations', 'inquiry_key')
            && Schema::hasColumn('conversations', 'buyer_archived_at') && Schema::hasColumn('conversations', 'seller_archived_at');
    }

    public static function canReply(Conversation $thread): bool
    {
        if ($thread->listing_id) {
            return true;
        }

        if ($thread->direct_kind !== null) {
            return DirectMessaging::canReply($thread);
        }

        if (! self::enabled() || ! $thread->inquiry_business_id) {
            return false;
        }
        if ($thread->inquiry_job_id && ! JobPosting::whereKey($thread->inquiry_job_id)
            ->whereIn('status', ['approved', 'expired'])
            ->whereHas('branch', fn ($branch) => $branch->open()->where('business_id', $thread->inquiry_business_id))->exists()) {
            return false;
        }

        $business = Business::whereKey($thread->inquiry_business_id)->where('status', 'active')->whereHas('openLocations')->first();
        if (! $business) {
            return false;
        }
        $owners = $business->members()->wherePivot('role', 'owner')->get(['users.id', 'users.email_verified_at']);

        return $owners->count() === 1 && $owners->first()->id === $thread->seller_id && $owners->first()->hasVerifiedEmail();
    }
}
