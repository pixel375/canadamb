<?php

namespace App\Support;

use InvalidArgumentException;
use JsonException;

final class StripeTestEvent
{
    /**
     * Verify the raw signed body before decoding. Reject live-mode and stale events.
     * This verifier is not exposed as an HTTP endpoint.
     */
    public static function verify(string $body, string $header, string $secret, int $now): array
    {
        if (! str_starts_with($secret, 'whsec_') || strlen($secret) < 12 || strlen($body) > 262144 || strlen($header) > 4096) {
            throw new InvalidArgumentException('Invalid test event.');
        }
        $timestamps = [];
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($key === 't' && ctype_digit($value) && strlen($value) <= 12) {
                $timestamps[] = (int) $value;
            }
            if ($key === 'v1' && preg_match('/\A[0-9a-f]{64}\z/', $value)) {
                $signatures[] = $value;
            }
        }
        if (count($timestamps) !== 1 || abs($now - $timestamps[0]) > 300) {
            throw new InvalidArgumentException('Invalid event timestamp.');
        }
        $expected = hash_hmac('sha256', $timestamps[0].'.'.$body, $secret);
        $valid = false;
        foreach ($signatures as $signature) {
            $valid = hash_equals($expected, $signature) || $valid;
        }
        if (! $valid) {
            throw new InvalidArgumentException('Invalid event signature.');
        }
        try {
            $event = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('Invalid event body.');
        }
        if (! is_array($event) || ($event['livemode'] ?? null) !== false
            || ! is_string($event['id'] ?? null) || ! preg_match('/\Aevt_[A-Za-z0-9_]{1,200}\z/', $event['id'])
            || ! in_array($event['type'] ?? null, ['checkout.session.completed', 'checkout.session.async_payment_succeeded', 'checkout.session.async_payment_failed', 'checkout.session.expired'], true)
            || ! is_array($event['data']['object'] ?? null)) {
            throw new InvalidArgumentException('Unsupported test event.');
        }
        $object = $event['data']['object'];
        if (($object['object'] ?? null) !== 'checkout.session' || ($object['livemode'] ?? null) !== false
            || ! is_string($object['id'] ?? null) || ! str_starts_with($object['id'], 'cs_test_')
            || ($object['mode'] ?? null) !== 'payment' || ($object['currency'] ?? null) !== 'cad'
            || ! is_int($object['amount_total'] ?? null) || $object['amount_total'] < 50
            || ! is_string($object['client_reference_id'] ?? null)) {
            throw new InvalidArgumentException('Invalid test checkout session.');
        }

        return [
            'event_id' => $event['id'], 'type' => $event['type'],
            'session_id' => $object['id'], 'reference' => $object['client_reference_id'],
            'amount_cents' => $object['amount_total'], 'currency' => 'cad',
            'paid' => ($object['payment_status'] ?? null) === 'paid',
            'payload_sha256' => hash('sha256', $body),
        ];
    }
}
