<?php

namespace App\Support;

use App\Models\Site;

final class NetworkUrl
{
    public static function to(Site $site, string $path = '/'): string
    {
        // Ports and cleartext canonical URLs are available only in local development.
        $url = parse_url((string) config('app.url'));
        $local = app()->isLocal();
        $scheme = $local ? ($url['scheme'] ?? 'http') : 'https';
        $port = $local && isset($url['port']) ? ':'.$url['port'] : '';

        return $scheme.'://'.$site->domain->host.$port.$path;
    }

    public static function pathOn(Site $site, mixed $url): ?string
    {
        $origin = self::to($site, '');
        if (! is_string($url) || ! str_starts_with($url, $origin.'/') || preg_match('/[\x00-\x20\x7f]/', $url) || str_contains($url, '\\')) {
            return null;
        }
        $parts = parse_url($url);
        if ($parts === false || isset($parts['fragment'])) {
            return null;
        }
        $path = $parts['path'];
        $decoded = rawurldecode($path);
        if (str_starts_with($decoded, '//') || str_contains($decoded, '\\') || str_contains($decoded, '%')
            || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $decoded) || preg_match('/[\x00-\x1f\x7f]/', $decoded)) {
            return null;
        }

        return $path.(isset($parts['query']) ? '?'.$parts['query'] : '');
    }

    public static function main(): Site
    {
        $attributes = request()->attributes;
        if ($attributes->has('site')) {
            $site = $attributes->get('site');
            if ($site->isNetwork()) {
                return $site;
            }

            if (! $attributes->has('main_network_site')) {
                $attributes->set('main_network_site', Site::with('domain')->where('code', 'network')->firstOrFail());
            }

            return $attributes->get('main_network_site');
        }

        return Site::with('domain')->where('code', 'network')->firstOrFail();
    }
}
