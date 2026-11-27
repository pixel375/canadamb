<?php

namespace App\Support;

use App\Models\Site;
use Illuminate\Support\Facades\Schema;

final class PushDevices
{
    public static function enabled(): bool
    {
        return (bool) config('network.push_devices') && Schema::hasTable('push_devices');
    }

    public static function publicKey(): ?string
    {
        $key = config('network.push_public_key');

        return is_string($key) && self::keyValid($key, 65, true) ? $key : null;
    }

    public static function available(Site $site): bool
    {
        return self::enabled() && self::publicKey() !== null
            && (app(NetworkSettings::class)->forSite($site)['pwa_install_enabled'] ?? true);
    }

    public static function keyValid(string $value, int $length, bool $point = false): bool
    {
        if (! preg_match('/^[A-Za-z0-9_-]+$/D', $value)) {
            return false;
        }
        $bytes = base64_decode(strtr($value, '-_', '+/'), true);

        return $bytes !== false && strlen($bytes) === $length
            && rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=') === $value
            && (! $point || $bytes[0] === chr(4));
    }

    // No outbound network request is made. Restrict stored endpoints to
    // recognized providers; a future sender must independently revalidate.
    public static function endpointValid(string $endpoint): bool
    {
        if (strlen($endpoint) > 2048 || preg_match('/[\x00-\x20\x7f]/', $endpoint) || str_contains($endpoint, chr(92))) {
            return false;
        }
        $url = parse_url($endpoint);
        if ($url === false || ($url['scheme'] ?? '') !== 'https' || isset($url['user']) || isset($url['pass'])
            || isset($url['port']) || isset($url['fragment']) || empty($url['path']) || $url['path'] === '/') {
            return false;
        }
        $host = strtolower($url['host'] ?? '');
        if (strlen($host) > 253 || ! preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\\.)+[a-z0-9-]+$/D', $host)) {
            return false;
        }

        return in_array($host, ['fcm.googleapis.com', 'updates.push.services.mozilla.com', 'web.push.apple.com'], true)
            || str_ends_with($host, '.push.apple.com') || str_ends_with($host, '.notify.windows.com');
    }
}
