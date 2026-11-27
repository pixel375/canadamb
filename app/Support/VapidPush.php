<?php

namespace App\Support;

use Firebase\JWT\JWT;
use OpenSSLAsymmetricKey;
use Throwable;

/**
 * Empty-body Web Push (RFC 8030, RFC 8292). No private data leaves the
 * application; the existing audited Composer JWT dependency signs VAPID.
 */
class VapidPush
{
    public static function signingKey(): ?OpenSSLAsymmetricKey
    {
        $encoded = config('network.push_private_key');
        $public = PushDevices::publicKey();
        $subject = config('network.push_subject');
        if (! is_string($encoded) || ! $public || ! is_string($subject)
            || ! str_starts_with($subject, 'mailto:') || ! filter_var(substr($subject, 7), FILTER_VALIDATE_EMAIL)
            || ! extension_loaded('openssl') || ! extension_loaded('curl') || ! class_exists(JWT::class)) {
            return null;
        }
        $pem = base64_decode($encoded, true);
        if (! $pem || strlen($pem) > 4096) {
            return null;
        }
        $key = @openssl_pkey_get_private($pem);
        if (! $key) {
            return null;
        }
        $details = openssl_pkey_get_details($key);
        $ec = $details['ec'] ?? [];
        if (($ec['curve_name'] ?? null) !== 'prime256v1' || ! isset($ec['x'], $ec['y'])) {
            return null;
        }
        $point = chr(4).str_pad($ec['x'], 32, chr(0), STR_PAD_LEFT).str_pad($ec['y'], 32, chr(0), STR_PAD_LEFT);

        return hash_equals($public, rtrim(strtr(base64_encode($point), '+/', '-_'), '=')) ? $key : null;
    }

    public function headers(string $endpoint, OpenSSLAsymmetricKey $key): array
    {
        $host = strtolower((string) parse_url($endpoint, PHP_URL_HOST));
        $token = JWT::encode(['aud' => 'https://'.$host, 'exp' => now()->addMinutes(30)->timestamp, 'sub' => config('network.push_subject')], $key, 'ES256');

        return ['Authorization: vapid t='.$token.', k='.PushDevices::publicKey(), 'TTL: 300', 'Urgency: normal', 'Topic: canadamb-activity', 'Content-Length: 0'];
    }

    // Return only numeric status; provider URLs, tokens and responses must
    // never enter logs. No redirect, proxy, private DNS address or payload.
    public function send(string $endpoint, OpenSSLAsymmetricKey $key): int
    {
        if (! PushDevices::endpointValid($endpoint)) {
            return 0;
        }
        try {
            $host = strtolower((string) parse_url($endpoint, PHP_URL_HOST));
            $addresses = @gethostbynamel($host) ?: [];
            if (! $addresses || collect($addresses)->contains(fn ($address) => ! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_GLOBAL_RANGE))) {
                return 0;
            }
            $curl = curl_init($endpoint);
            if (! $curl) {
                return 0;
            }
            curl_setopt_array($curl, [
                CURLOPT_POST => true, CURLOPT_POSTFIELDS => '', CURLOPT_HTTPHEADER => $this->headers($endpoint, $key),
                CURLOPT_PROTOCOLS_STR => 'HTTPS', CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROXY => '',
                CURLOPT_RESOLVE => [$host.':443:'.$addresses[0]], CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 5, CURLOPT_WRITEFUNCTION => fn ($handle, string $bytes) => strlen($bytes),
            ]);
            $ok = curl_exec($curl);

            return $ok === false ? 0 : (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        } catch (Throwable) {
            return 0;
        }
    }
}
