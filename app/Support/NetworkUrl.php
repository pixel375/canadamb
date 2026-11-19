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
