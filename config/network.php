<?php

return [
    'staging' => env('CANADAMB_STAGING', false),
    'prelaunch' => env('CANADAMB_PRELAUNCH', false),
    'prelaunch_ips' => array_values(array_filter(array_map('trim', explode(',', (string) env('CANADAMB_PRELAUNCH_IPS', ''))))),
    'development_hosts' => env('CANADAMB_DEVELOPMENT_HOSTS', false),
    'sso_enabled' => env('CANADAMB_SSO_ENABLED', true),
    'news_ingest_token' => env('CANADAMB_NEWS_INGEST_TOKEN'),
    'news_polling' => env('CANADAMB_NEWS_POLLING', false),
    'property_drafts' => env('CANADAMB_PROPERTY_DRAFTS_ENABLED', false),
    'media_disk' => env('CANADAMB_MEDIA_DISK', 'local'),
    'cron_queue_drain' => env('CANADAMB_CRON_QUEUE_DRAIN', false),
    'trusted_proxies' => array_values(array_filter(array_map('trim', explode(',', (string) env('CANADAMB_TRUSTED_PROXIES', ''))))),
];
