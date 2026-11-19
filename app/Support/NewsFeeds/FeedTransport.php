<?php

namespace App\Support\NewsFeeds;

class FeedTransport
{
    public const MAX_BYTES = 1_048_576;

    public function fetch(string $url, ?int $deadline = null): string
    {
        $url = FeedUrl::normalize($url) ?? throw new FeedFailure('Invalid HTTPS feed URL.');
        $host = parse_url($url, PHP_URL_HOST);
        $addresses = gethostbynamel($host);
        if (! $addresses) {
            throw new FeedFailure('Feed hostname has no IPv4 address.');
        }
        foreach ($addresses as $address) {
            if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE | FILTER_FLAG_IPV4)
                || (int) explode('.', $address)[0] >= 224) {
                throw new FeedFailure('Feed hostname must resolve only to public addresses.');
            }
        }

        $timeout = $deadline === null ? 5000 : min(5000, intdiv($deadline - hrtime(true), 1_000_000));
        if ($timeout <= 0) {
            throw new FeedFailure('The polling batch ran out of time resolving the feed hostname.');
        }

        $handle = curl_init($url);
        if (! $handle) {
            throw new FeedFailure('A feed connection could not be initialized.');
        }

        $body = '';
        $tooLarge = false;
        $protocols = defined('CURLOPT_PROTOCOLS_STR') ? [CURLOPT_PROTOCOLS_STR => 'HTTPS'] : [CURLOPT_PROTOCOLS => CURLPROTO_HTTPS];
        $options = [
            CURLOPT_RESOLVE => [$host.':443:'.implode(',', $addresses)],
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROXY => '',
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_CONNECTTIMEOUT_MS => min(2500, $timeout),
            CURLOPT_TIMEOUT_MS => $timeout,
            CURLOPT_USERAGENT => 'CanadaMB News Feed Reader',
            CURLOPT_HTTPHEADER => ['Accept: application/rss+xml, application/atom+xml, application/xml, text/xml'],
            CURLOPT_ENCODING => '',
            CURLOPT_WRITEFUNCTION => function ($handle, string $chunk) use (&$body, &$tooLarge): int {
                if (strlen($body) + strlen($chunk) > self::MAX_BYTES) {
                    $tooLarge = true;

                    return 0;
                }
                $body .= $chunk;

                return strlen($chunk);
            },
        ] + $protocols;

        try {
            if (! curl_setopt_array($handle, $options)) {
                throw new FeedFailure('Feed connection safety options could not be set.');
            }
            $received = curl_exec($handle);
            $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        } finally {
            curl_close($handle);
        }

        if ($tooLarge) {
            throw new FeedFailure('Feed exceeded the 1 MiB response limit.');
        }
        if ($received === false) {
            throw new FeedFailure('The HTTPS feed request failed or timed out.');
        }
        if ($status !== 200) {
            throw new FeedFailure('Feed returned HTTP '.$status.'.');
        }

        return $body;
    }
}
