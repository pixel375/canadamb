<?php

namespace Tests\Feature;

use App\Support\StripeTestCheckout;
use App\Support\StripeTestEvent;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

class StripeTestCheckoutTest extends TestCase
{
    public function test_checkout_only_accepts_test_mode_and_uses_idempotency(): void
    {
        Http::preventStrayRequests();
        $reference = '11111111-2222-4333-8444-555555555555';
        Http::fake(['https://api.stripe.com/v1/checkout/sessions' => Http::response([
            'id' => 'cs_test_example', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_example',
            'livemode' => false, 'client_reference_id' => $reference, 'amount_total' => 3000, 'currency' => 'cad',
        ])]);
        $adapter = app(StripeTestCheckout::class);
        $this->assertSame('cs_test_example', $adapter->create('sk_test_fixture_only', $reference, 3000, now()->addHours(20)->timestamp)['id']);
        Http::assertSent(fn ($request) => $request->hasHeader('Idempotency-Key', 'canadamb-test-'.$reference)
            && $request['client_reference_id'] === $reference && $request['mode'] === 'payment');
        $this->expectException(InvalidArgumentException::class);
        $adapter->create('sk_live_fixture_only', $reference, 3000);
    }

    public function test_event_rejects_tampering_and_expired_signatures(): void
    {
        $raw = '{"livemode":false}';
        $secret = 'whsec_test_fixture_only';
        $timestamp = 1000;
        $signature = 't=1000,v1='.hash_hmac('sha256', $timestamp.'.'.$raw, $secret);
        foreach ([[$raw.' ', $timestamp], [$raw, 1301]] as [$body, $now]) {
            try {
                StripeTestEvent::verify($body, $signature, $secret, $now);
                $this->fail('Unexpected acceptance of invalid signed event.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('Invalid event', $exception->getMessage());
            }
        }
    }
}
