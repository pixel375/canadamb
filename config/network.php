<?php

return [
    'staging' => env('CANADAMB_STAGING', false),
    'prelaunch' => env('CANADAMB_PRELAUNCH', false),
    'prelaunch_ips' => array_values(array_filter(array_map('trim', explode(',', (string) env('CANADAMB_PRELAUNCH_IPS', ''))))),
    'development_hosts' => env('CANADAMB_DEVELOPMENT_HOSTS', false),
    'sso_enabled' => env('CANADAMB_SSO_ENABLED', true),
    'news_ingest_token' => env('CANADAMB_NEWS_INGEST_TOKEN'),
    'news_polling' => env('CANADAMB_NEWS_POLLING', false),
    'event_discussions' => env('CANADAMB_EVENT_DISCUSSIONS', false),
    'extended_messaging' => env('CANADAMB_EXTENDED_MESSAGING', false),
    'job_searches' => env('CANADAMB_JOB_SEARCHES', false),
    'business_changes' => env('CANADAMB_BUSINESS_CHANGES', false),
    'business_disputes' => env('CANADAMB_BUSINESS_DISPUTES', false),
    'business_access_requests' => env('CANADAMB_BUSINESS_ACCESS_REQUESTS', false),
    'content_reports' => env('CANADAMB_CONTENT_REPORTS', false),
    'commercial_test_checkout' => env('CANADAMB_COMMERCIAL_TEST_CHECKOUT', false),
    'commercial_trial_export' => env('CANADAMB_COMMERCIAL_TRIAL_EXPORT', false),
    'commercial_planning_records' => env('CANADAMB_COMMERCIAL_PLANNING_RECORDS', false),
    'property_drafts' => env('CANADAMB_PROPERTY_DRAFTS_ENABLED', false),
    // Separate, fail-closed switch for any future verified public publication.
    'real_estate_public_listings' => env('CANADAMB_REAL_ESTATE_PUBLIC_LISTINGS', false),
    'media_disk' => env('CANADAMB_MEDIA_DISK', 'local'),
    'cron_queue_drain' => env('CANADAMB_CRON_QUEUE_DRAIN', false),
    'trusted_proxies' => array_values(array_filter(array_map('trim', explode(',', (string) env('CANADAMB_TRUSTED_PROXIES', ''))))),
];
