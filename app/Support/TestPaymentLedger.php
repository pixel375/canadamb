<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class TestPaymentLedger
{
    /**
     * Accept only a verified test event. Never publishes a campaign or grants
     * an entitlement. The full payload and customer/card data are not retained.
     */
    public function reconcile(string $body, string $signature, string $secret, int $now): string
    {
        $event = StripeTestEvent::verify($body, $signature, $secret, $now);

        return DB::transaction(function () use ($event) {
            $attempt = DB::table('commercial_payment_attempts')->where('reference', $event['reference'])->lockForUpdate()->first();
            if (! $attempt || $attempt->provider !== 'stripe-test' || $attempt->currency !== $event['currency']
                || (int) $attempt->amount_cents !== $event['amount_cents'] || $attempt->provider_session_id !== $event['session_id']) {
                throw new InvalidArgumentException('Test event does not match an existing payment attempt.');
            }
            $existing = DB::table('commercial_payment_events')->where('provider_event_id', $event['event_id'])->first();
            if ($existing) {
                if ((int) $existing->attempt_id !== (int) $attempt->id || ! hash_equals($existing->payload_sha256, $event['payload_sha256'])) {
                    throw new InvalidArgumentException('Conflicting event identifier.');
                }

                return 'duplicate';
            }
            $outcome = 'ignored';
            if ($attempt->status !== 'paid') {
                if ($event['paid'] && in_array($event['type'], ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true)) {
                    DB::table('commercial_payment_attempts')->where('id', $attempt->id)->update(['status' => 'paid', 'settled_at' => now(), 'updated_at' => now()]);
                    $outcome = 'paid';
                } elseif (! $event['paid'] && in_array($event['type'], ['checkout.session.expired', 'checkout.session.async_payment_failed'], true)) {
                    $outcome = $event['type'] === 'checkout.session.expired' ? 'expired' : 'failed';
                    DB::table('commercial_payment_attempts')->where('id', $attempt->id)->update(['status' => $outcome, 'updated_at' => now()]);
                }
            }
            DB::table('commercial_payment_events')->insert([
                'attempt_id' => $attempt->id, 'provider_event_id' => $event['event_id'],
                'event_type' => $event['type'], 'payload_sha256' => $event['payload_sha256'],
                'outcome' => $outcome, 'created_at' => now(),
            ]);

            return $outcome;
        }, attempts: 3);
    }
}
