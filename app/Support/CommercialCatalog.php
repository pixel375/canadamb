<?php

namespace App\Support;

final class CommercialCatalog
{
    public const PRODUCTS = [
        'business-pro' => ['name' => 'Business Pro', 'period' => 'month', 'description' => 'Proposed optional business subscription. Entitlements are not yet implemented.'],
        'directory-sponsored' => ['name' => 'Sponsored directory placement', 'period' => '7 days', 'description' => 'A separately labelled business placement, outside organic directory results.'],
        'home-banner' => ['name' => 'Community homepage banner', 'period' => '7 days', 'description' => 'A labelled local advertisement on a community homepage.'],
        'marketplace-banner' => ['name' => 'Marketplace banner', 'period' => '7 days', 'description' => 'A separate advertising placement, not a ranked organic listing.'],
    ];

    public static function fromSettings(array $settings): array
    {
        $catalog = $settings['commercial_catalog'] ?? [];

        return collect(self::PRODUCTS)->mapWithKeys(function (array $product, string $key) use ($catalog) {
            $price = $catalog[$key]['price_cents'] ?? null;

            return [$key => $product + ['price_cents' => is_int($price) && $price >= 0 ? $price : null]];
        })->all();
    }
}
