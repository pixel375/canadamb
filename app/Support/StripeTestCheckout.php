<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

final class StripeTestCheckout
{
    /**
     * Unwired test-mode adapter. No route or scheduled task calls it.
     * Reference and amount must come from a persisted, server-created attempt.
     */
    public function create(string $secret, string $reference, int $cents, int $expiresAt): array
    {
        if (! str_starts_with($secret, 'sk_test_') || ! preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/', $reference) || $cents < 50 || $cents > 100000000) {
            throw new InvalidArgumentException('Invalid test checkout configuration.');
        }
        $response = Http::asForm()->withToken($secret)->withHeaders(['Idempotency-Key' => 'canadamb-test-'.$reference])
            ->connectTimeout(5)->timeout(15)->withoutRedirecting()
            ->post('https://api.stripe.com/v1/checkout/sessions', [
                'mode' => 'payment',
                'expires_at' => $expiresAt,
                'payment_method_types' => ['card'],
                'client_reference_id' => $reference,
                'success_url' => 'https://canadamb.ca/account/settings',
                'cancel_url' => 'https://canadamb.ca/account/settings',
                'line_items' => [[
                    'quantity' => 1,
                    'price_data' => [
                        'currency' => 'cad', 'unit_amount' => $cents,
                        'product_data' => ['name' => 'CanadaMB test advertising checkout'],
                    ],
                ]],
            ]);
        $data = $response->json();
        if (! $response->successful() || ! is_array($data) || ($data['livemode'] ?? null) !== false
            || ! is_string($data['id'] ?? null) || ! str_starts_with($data['id'], 'cs_test_')
            || ! is_string($data['url'] ?? null) || parse_url($data['url'], PHP_URL_SCHEME) !== 'https'
            || parse_url($data['url'], PHP_URL_HOST) !== 'checkout.stripe.com'
            || ($data['client_reference_id'] ?? null) !== $reference || ($data['amount_total'] ?? null) !== $cents
            || ($data['currency'] ?? null) !== 'cad') {
            throw new RuntimeException('Test checkout response did not match the payment attempt.');
        }

        return ['id' => $data['id'], 'url' => $data['url']];
    }
}
