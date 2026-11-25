<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class StagedFeatures
{
    // Table/column presence is an inventory, not a full constraint or data audit.
    public const DEFINITIONS = [
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
            foreach ($tables as $table) {
                $checks[] = Schema::hasTable($table);
            }
            foreach ($columns as $table => $names) {
                foreach ($names as $name) {
                    $checks[] = Schema::hasColumn($table, $name);
                }
            }
            $complete = ! in_array(false, $checks, true);
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
                'note' => $note,
            ];
        }
        foreach (self::DEFINITIONS as [, , $flag, , , $dependencies]) {
            foreach ($dependencies as $dependency) {
                if (! $rows[$dependency]['ready'] || ! $rows[$dependency]['configured']) {
                    $rows[$flag]['ready'] = false;
                    if ($rows[$flag]['configured']) {
                        $rows[$flag]['state'] .= '; dependency not ready';
                    }
                }
            }
        }

        return $rows;
    }
}
