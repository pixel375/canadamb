<?php

namespace App\Support;

use App\Models\Business;
use App\Models\Conversation;
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

        return self::enabled() && Business::whereKey($thread->inquiry_business_id)->where('status', 'active')
            ->whereHas('members', fn ($query) => $query->whereKey($thread->seller_id)->where('business_memberships.role', 'owner'))->exists();
    }
}
