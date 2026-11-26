<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class StagedFeatures
{
    // Inspect columns and selected integrity constraints; not a full schema or data audit.
    private const COLUMNS = [
        'advertising_campaigns' => ['id', 'business_id', 'site_id', 'author_id', 'reviewer_id', 'placement', 'title', 'body', 'starts_on', 'weeks', 'estimated_cents', 'rate_revision_id', 'status', 'review_note', 'reviewed_at', 'created_at', 'updated_at'],
        'business_entitlements' => ['id', 'business_id', 'granted_by', 'product', 'starts_on', 'ends_on', 'revoked_at', 'created_at', 'updated_at'],
        'commercial_payment_attempts' => ['id', 'campaign_id', 'reference', 'provider', 'provider_session_id', 'amount_cents', 'currency', 'status', 'settled_at', 'created_at', 'updated_at'],
        'commercial_payment_events' => ['id', 'attempt_id', 'provider_event_id', 'event_type', 'payload_sha256', 'outcome', 'created_at'],
        'content_reports' => ['id', 'reporter_id', 'site_id', 'target_type', 'target_id', 'reason', 'explanation', 'status', 'reviewer_id', 'review_note', 'resolved_at', 'created_at', 'updated_at'],
        'community_event_discussions' => ['event_id', 'thread_id'],
        'user_signup_locations' => ['user_id', 'location_id', 'created_at'],
        'community_reply_links' => ['reply_id', 'parent_reply_id'],
        'owner_notifications' => ['id', 'user_id', 'kind', 'target_id', 'decision', 'read_at', 'created_at'],
        'push_devices' => ['id', 'user_id', 'site_id', 'endpoint_hash', 'subscription', 'created_at', 'updated_at'],
        'broadcast_consents' => ['user_id', 'opted_in', 'updated_at'],
        'business_access_requests' => ['id', 'business_id', 'requester_id', 'recipient_id', 'kind', 'status', 'expires_at', 'reviewer_id', 'review_note', 'created_at', 'updated_at'],
        'business_disputes' => ['id', 'business_id', 'claimant_id', 'site_id', 'contact_email', 'explanation', 'status', 'reviewer_id', 'review_note', 'reviewed_at', 'created_at', 'updated_at'],
        'business_change_requests' => ['id', 'business_id', 'requester_id', 'branch_id', 'location_id', 'kind', 'value', 'previous_value', 'status', 'reviewer_id', 'review_note', 'reviewed_at', 'created_at', 'updated_at'],
        'job_searches' => ['id', 'user_id', 'site_id', 'name', 'filters', 'filter_key', 'alerts', 'checked_at', 'created_at', 'updated_at'],
        'message_attachments' => ['id', 'message_id', 'image_path', 'thumbnail_path', 'created_at', 'updated_at'],
        'business_inquiry_settings' => ['business_id', 'owner_id', 'business_inquiries', 'job_inquiries', 'updated_at'],
        'account_privacy_requests' => ['id', 'user_id', 'kind', 'status', 'reviewer_id', 'review_note', 'reviewed_at', 'created_at', 'updated_at'],
        'notification_preferences' => ['user_id', 'discussion_replies', 'job_summary', 'updated_at'],
        'news_candidate_reviews' => ['candidate_id', 'reviewer_id', 'dismissed', 'reason', 'updated_at'],
        'marketplace_searches' => ['id', 'user_id', 'site_id', 'name', 'filters', 'filter_key', 'created_at', 'updated_at'],
    ];

    private const UNIQUE_KEYS = [
        'advertising_campaigns' => [['id']],
        'business_entitlements' => [['id']],
        'commercial_payment_attempts' => [['id'], ['campaign_id'], ['reference'], ['provider_session_id']],
        'commercial_payment_events' => [['id'], ['provider_event_id']],
        'content_reports' => [['id'], ['reporter_id', 'target_type', 'target_id']],
        'community_event_discussions' => [['event_id'], ['thread_id']],
        'user_signup_locations' => [['user_id']],
        'community_reply_links' => [['reply_id']],
        'owner_notifications' => [['id']],
        'push_devices' => [['id'], ['endpoint_hash']],
        'broadcast_consents' => [['user_id']],
        'business_access_requests' => [['id']],
        'business_disputes' => [['id']],
        'business_change_requests' => [['id']],
        'job_searches' => [['id'], ['user_id', 'site_id', 'filter_key']],
        'conversations' => [['inquiry_key']],
        'message_attachments' => [['id'], ['message_id'], ['image_path'], ['thumbnail_path']],
        'business_inquiry_settings' => [['business_id']],
        'account_privacy_requests' => [['id']],
        'notification_preferences' => [['user_id']],
        'news_candidate_reviews' => [['candidate_id']],
        'marketplace_searches' => [['id'], ['user_id', 'site_id', 'filter_key']],
    ];

    public const DEFINITIONS = [
        ['2026_11_26_000019_create_broadcast_consents', 'Broadcast audience preview', 'broadcast_previews', ['broadcast_consents'], [], [], 'Separate optional in-site announcements consent; previews only, no sends.'],
        ['2026_11_26_000018_create_push_devices', 'Web Push device registration', 'push_devices', ['push_devices'], [], [], 'Opt-in registry only; no sender, external delivery or unsolicited prompts.'],
        ['2026_11_26_000017_create_owner_notifications', 'Owner status notifications', 'owner_notifications', ['owner_notifications'], [], [], 'Private moderation and expiry alerts; review retention before activation.'],
        ['2026_11_26_000016_create_community_reply_links', 'Replies to comments', 'reply_links', ['community_reply_links'], [], [], 'Public-only parent references and moderation-scoped notification delivery.'],
        ['2026_11_26_000015_create_user_signup_locations', 'Registration locations', 'signup_locations', ['user_signup_locations'], [], [], 'Public community only; consent and existing-account handling; central verification remains required.'],
        ['2026_11_24_000001_create_commercial_planning_tables', 'Commercial planning', 'commercial_planning_records', ['advertising_campaigns', 'business_entitlements'], [], [], 'Review commercial retention and campaign approval.'],
        ['2026_11_24_000002_create_test_payment_ledger', 'Test payment ledger', 'commercial_test_checkout', ['commercial_payment_attempts', 'commercial_payment_events'], [], ['commercial_planning_records'], 'Test-only; no live charges or entitlements.'],
        ['2026_11_24_000003_create_content_reports', 'Content reporting', 'content_reports', ['content_reports'], [], [], 'Review moderation staffing and retention.'],
        ['2026_11_24_000004_link_event_discussions', 'Event discussions', 'event_discussions', ['community_event_discussions'], [], [], 'Verify linked moderation and cancellation.'],
        ['2026_11_24_000005_create_business_access_requests', 'Business invitations and transfer', 'business_access_requests', ['business_access_requests'], [], [], 'Review owner transfer and access policy.'],
        ['2026_11_24_000006_create_business_disputes', 'Ownership disputes', 'business_disputes', ['business_disputes'], [], [], 'Intake and triage only.'],
        ['2026_11_24_000007_create_business_change_requests', 'Business changes', 'business_changes', ['business_change_requests'], [], [], 'Independent address and contact review.'],
        ['2026_11_25_000008_create_job_searches', 'Saved job searches', 'job_searches', ['job_searches'], [], [], 'In-app on-demand alerts only.'],
        ['2026_11_25_000009_extend_conversations_for_inquiries', 'Extended messaging', 'extended_messaging', [], ['conversations' => ['inquiry_key', 'inquiry_business_id', 'inquiry_job_id', 'inquiry_title', 'buyer_archived_at', 'seller_archived_at']], [], 'Existing-table alteration; review rollback and participant privacy.'],
        ['2026_11_25_000010_create_message_attachments_and_inquiry_settings', 'Private photo attachments', 'message_attachments', ['message_attachments', 'business_inquiry_settings'], [], [], 'Review retention, orphan inventory and private backups.'],
        ['2026_11_25_000010_create_message_attachments_and_inquiry_settings', 'Business inquiry controls', 'business_inquiry_controls', ['message_attachments', 'business_inquiry_settings'], [], ['extended_messaging'], 'Owner-bound consent; depends on extended messaging.'],
        ['2026_11_25_000011_create_account_privacy_requests', 'Account privacy requests', 'privacy_requests', ['account_privacy_requests'], [], [], 'Intake only; verified fulfillment and retention required.'],
        ['2026_11_25_000012_create_notification_preferences', 'Notification preferences', 'notification_preferences', ['notification_preferences'], [], [], 'In-app preferences, no email or push delivery.'],
        ['2026_11_25_000014_create_marketplace_searches', 'Saved Marketplace searches', 'marketplace_searches', ['marketplace_searches'], [], [], 'Private portal-specific searches; no external alerts.'],
        ['2026_11_25_000013_create_news_candidate_reviews', 'News candidate reviews', 'news_candidate_review', ['news_candidate_reviews'], [], [], 'Candidate triage; no automated publication.'],
    ];

    public static function inspect(): array
    {
        $migrationTable = config('database.migrations.table', 'migrations');
        $history = Schema::hasTable($migrationTable) ? DB::table($migrationTable)->pluck('migration')->all() : [];
        $rows = [];
        foreach (self::DEFINITIONS as [$migration, $label, $flag, $tables, $columns, , $note]) {
            $checks = [];
            $problems = [];
            foreach ($tables as $table) {
                $exists = Schema::hasTable($table);
                $checks[] = $exists;
                if (! $exists) {
                    $problems[] = 'Missing table: '.$table;

                    continue;
                }
                $columns[$table] = self::COLUMNS[$table];
            }
            foreach ($columns as $table => $names) {
                $available = Schema::hasTable($table) ? Schema::getColumnListing($table) : [];
                foreach ($names as $name) {
                    $exists = in_array($name, $available, true);
                    $checks[] = $exists;
                    if (! $exists) {
                        $problems[] = 'Missing column: '.$table.'.'.$name;
                    }
                }
                if ($available === []) {
                    continue;
                }
                // Only inspect constraints on tables that exist. In the
                // conversation extension, a pre-existing listing_id is not
                // proof that the new migration was applied.
                $keys = Schema::getIndexes($table);
                foreach (self::UNIQUE_KEYS[$table] ?? [] as $key) {
                    $found = collect($keys)->contains(fn ($index) => ($index['unique'] || $index['primary']) && $index['columns'] === $key);
                    if (! $found) {
                        $problems[] = 'Missing unique key: '.$table.' ('.implode(', ', $key).')';
                    }
                }
                if ($table === 'conversations' && in_array('inquiry_key', $available, true)) {
                    $listing = collect(Schema::getColumns($table))->firstWhere('name', 'listing_id');
                    if (! ($listing['nullable'] ?? false)) {
                        $problems[] = 'Conversation listing_id must be nullable.';
                    }
                }
            }
            $complete = $problems === [] && ! in_array(false, $checks, true);
            $present = in_array(true, $checks, true);
            $recorded = in_array($migration, $history, true);
            $rows[$flag] = [
                'label' => $label, 'migration' => $migration, 'configured' => (bool) config('network.'.$flag),
                'ready' => $complete && $recorded,
                'state' => match (true) {
                    $complete && $recorded => 'Recorded schema present',
                    $recorded => 'Recorded but incomplete schema',
                    $present => 'Unrecorded or partial schema',
                    default => 'Not applied',
                },
                'note' => $note, 'problems' => $present || $recorded ? $problems : [],
            ];
        }
        foreach (self::DEFINITIONS as [, , $flag, , , $dependencies]) {
            foreach ($dependencies as $dependency) {
                if (! $rows[$dependency]['ready'] || ! $rows[$dependency]['configured']) {
                    $rows[$flag]['ready'] = false;
                    $rows[$flag]['problems'][] = 'Dependency not ready: '.$rows[$dependency]['label'];
                    if ($rows[$flag]['configured']) {
                        $rows[$flag]['state'] .= '; dependency not ready';
                    }
                }
            }
        }

        return $rows;
    }
}
