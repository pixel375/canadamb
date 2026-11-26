<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class PrivacyImpact
{
    private const RECORDS = [
        'Marketplace listings' => ['listings', 'user_id'],
        'Property records' => ['property_listings', 'owner_id'],
        'Business memberships' => ['business_memberships', 'user_id'],
        'Business claims' => ['business_claims', 'user_id'],
        'Business updates' => ['business_updates', 'author_id'],
        'Jobs authored' => ['job_postings', 'author_id'],
        'Discussions authored' => ['community_threads', 'author_id'],
        'Replies authored' => ['community_replies', 'author_id'],
        'Events authored' => ['community_events', 'author_id'],
        'Messages sent' => ['messages', 'sender_id'],
        'Listing reports' => ['listing_reports', 'reporter_id'],
        'Content reports' => ['content_reports', 'reporter_id'],
        'Business disputes' => ['business_disputes', 'claimant_id'],
        'Business change requests' => ['business_change_requests', 'requester_id'],
        'Saved Marketplace searches' => ['marketplace_searches', 'user_id'],
        'Saved job searches' => ['job_searches', 'user_id'],
        'Privacy requests' => ['account_privacy_requests', 'user_id'],
        'Saved listings' => ['listing_favourites', 'user_id'],
        'Saved jobs' => ['job_posting_bookmarks', 'user_id'],
        'Followed discussions' => ['community_thread_follows', 'user_id'],
        'Discussion notifications' => ['community_notifications', 'user_id'],
        'Notification preferences' => ['notification_preferences', 'user_id'],
        'Owner submission notifications' => ['owner_notifications', 'user_id'],
        'Push device registrations' => ['push_devices', 'user_id'],
        'Blocked members' => ['user_blocks', 'user_id'],
        'Blocked by other members' => ['user_blocks', 'blocked_id'],
        'Commercial trials granted as operator' => ['business_entitlements', 'granted_by'],
        'Content reports reviewed as operator' => ['content_reports', 'reviewer_id'],
        'Privacy requests reviewed as operator' => ['account_privacy_requests', 'reviewer_id'],
        'Advertising requests authored' => ['advertising_campaigns', 'author_id'],
        'Business inquiry preferences' => ['business_inquiry_settings', 'owner_id'],
        'Sessions' => ['sessions', 'user_id'],
        'OAuth access tokens' => ['oauth_access_tokens', 'user_id'],
        'Audit events as actor' => ['audit_logs', 'actor_id'],
    ];

    public static function counts(int $member): array
    {
        $counts = [];
        foreach (self::RECORDS as $label => [$table, $column]) {
            $counts[$label] = Schema::hasTable($table) && Schema::hasColumn($table, $column)
                ? DB::table($table)->where($column, $member)->count() : null;
        }
        $counts['Business ownership memberships'] = DB::table('business_memberships')->where('user_id', $member)->where('role', 'owner')->count();
        $counts['Shared conversations'] = DB::table('conversations')->where(fn ($query) => $query->where('buyer_id', $member)->orWhere('seller_id', $member))->count();
        $counts['Business access requests'] = Schema::hasTable('business_access_requests')
            ? DB::table('business_access_requests')->where(fn ($query) => $query->where('requester_id', $member)->orWhere('recipient_id', $member))->count() : null;
        $counts['Sent photo attachments'] = Schema::hasTable('message_attachments')
            ? DB::table('message_attachments')->join('messages', 'messages.id', '=', 'message_attachments.message_id')->where('messages.sender_id', $member)->count() : null;

        $counts['Received photo attachments'] = Schema::hasTable('message_attachments')
            ? DB::table('message_attachments')->join('messages', 'messages.id', '=', 'message_attachments.message_id')
                ->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
                ->where('messages.sender_id', '!=', $member)->where(fn ($query) => $query->where('conversations.buyer_id', $member)->orWhere('conversations.seller_id', $member))->count() : null;

        $counts['Listing photos'] = DB::table('listing_media')->join('listings', 'listings.id', '=', 'listing_media.listing_id')->where('listings.user_id', $member)->count();
        $counts['Private property photos'] = DB::table('property_photos')->join('property_listings', 'property_listings.id', '=', 'property_photos.property_listing_id')->where('property_listings.owner_id', $member)->count();

        return $counts;
    }
}
