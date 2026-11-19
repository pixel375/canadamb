<?php

namespace App\Support\NewsFeeds;

final class FeedUrl
{
    public static function normalize(string $url, ?string $approvedHost = null): ?string
    {
        if (strlen($url) > 1024 || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');
        if (($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])
            || ($parts['port'] ?? 443) !== 443 || ($approvedHost !== null && strcasecmp($host, $approvedHost) !== 0)
            || ! preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,62}$/D', $host)) {
            return null;
        }

        return 'https://'.$host.($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
    }
}
