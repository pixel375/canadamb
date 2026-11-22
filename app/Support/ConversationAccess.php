<?php

namespace App\Support;

use App\Models\Conversation;
use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class ConversationAccess
{
    public const TYPES = ['marketplace' => 'Marketplace', 'business' => 'Businesses', 'job' => 'Jobs'];

    public static function forMember(Site $site, int $user): Builder
    {
        $query = Conversation::query()->whereRaw(self::memberSql('conversations', '?', '?'), [$user, $user]);
        if ($site->location_id !== null) {
            $query->whereRaw(self::locationSql('conversations', '?', '?'), [$site->location_id, $site->location_id]);
        }

        return $query;
    }

    /** SQL aliases and marker expressions are supplied only by server-owned queries. */
    public static function memberSql(string $thread, string $buyer, string $seller): string
    {
        return "($thread.buyer_id = $buyer OR ($thread.seller_id = $seller AND ".self::responderSql($thread).'))';
    }

    public static function responderSql(string $thread): string
    {
        return "($thread.context_type = 'marketplace' OR EXISTS (
            SELECT 1 FROM business_memberships AS representative
            WHERE representative.user_id = $thread.seller_id
              AND representative.role IN ('owner', 'manager')
              AND (
                ($thread.context_type = 'business' AND representative.business_id = (
                    SELECT contact_branch.business_id FROM business_locations AS contact_branch
                    WHERE contact_branch.id = $thread.business_location_id
                ))
                OR ($thread.context_type = 'job' AND representative.business_id = (
                    SELECT hiring_branch.business_id FROM job_postings AS hiring_job
                    JOIN business_locations AS hiring_branch ON hiring_branch.id = hiring_job.business_location_id
                    WHERE hiring_job.id = $thread.job_posting_id
                ))
              )
        ))";
    }

    public static function locationSql(string $thread, string $listingLocation, string $organizationLocation): string
    {
        return "(($thread.context_type = 'marketplace' AND EXISTS (
            SELECT 1 FROM listings AS scoped_listing
            WHERE scoped_listing.id = $thread.listing_id AND scoped_listing.location_id = $listingLocation
        )) OR ($thread.context_type IN ('business', 'job') AND $thread.location_id = $organizationLocation))";
    }

    public static function unblockedSql(string $thread): string
    {
        return "NOT EXISTS (SELECT 1 FROM user_blocks AS peer_block
            WHERE (peer_block.user_id = $thread.buyer_id AND peer_block.blocked_id = $thread.seller_id)
               OR (peer_block.user_id = $thread.seller_id AND peer_block.blocked_id = $thread.buyer_id))";
    }

    public static function unreadSql(string $thread, string $message, string $markerUser, string $reader): string
    {
        return "($message.id > CASE WHEN $thread.buyer_id = $markerUser
            THEN $thread.buyer_last_read_id ELSE $thread.seller_last_read_id END
            AND NOT EXISTS (SELECT 1 FROM message_reads AS seen
                WHERE seen.message_id = $message.id AND seen.user_id = $reader))";
    }

    public static function blocked(int $first, int $second, bool $lock = false): bool
    {
        return DB::table('user_blocks')->where(fn ($blocks) => $blocks
            ->where(['user_id' => $first, 'blocked_id' => $second])
            ->orWhere(['user_id' => $second, 'blocked_id' => $first]))
            ->when($lock, fn ($query) => $query->lockForUpdate())->first(['user_id']) !== null;
    }
}
