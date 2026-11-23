<?php

namespace App\Support;

/**
 * Browsing groups are presentation-only. Existing listing_categories.slug values
 * remain the real leaf categories, preserving historic listings and links.
 */
final class MarketplaceCategories
{
    public const GROUPS = [
        'vehicles' => [
            'name' => 'Vehicles & Motors',
            'subtitle' => 'Cars, RVs, trailers, parts and powersports',
            'icon' => '🚗',
            'children' => ['cars', 'powersports', 'rvs', 'trailers', 'auto-parts'],
        ],
        'home' => [
            'name' => 'Home & Living',
            'subtitle' => 'Furniture, appliances, tools and garden',
            'icon' => '🏠',
            'children' => ['furniture', 'appliances', 'home', 'tools'],
        ],
        'tech' => [
            'name' => 'Electronics & Tech',
            'subtitle' => 'Computers, phones, games and electronics',
            'icon' => '💻',
            'children' => ['electronics', 'computers', 'phones', 'gaming'],
        ],
        'farm-outdoors' => [
            'name' => 'Farm & Outdoors',
            'subtitle' => 'Agricultural equipment and outdoor gear',
            'icon' => '🚜',
            'children' => ['farm', 'outdoor'],
        ],
        'lifestyle' => [
            'name' => 'Lifestyle & Family',
            'subtitle' => 'Sports, fashion, children, pets and collectibles',
            'icon' => '🧸',
            'children' => ['sports', 'clothing', 'collectibles', 'pets', 'kids'],
        ],
        'miscellaneous' => [
            'name' => 'Other Listings',
            'subtitle' => 'Everything else you might be looking for',
            'icon' => '📦',
            'children' => ['other'],
        ],
    ];

    /**
     * Retain third-party or migration-added category slugs as a fallback.
     * Future hierarchical DB categories can replace this view-only grouping.
     */
    public static function grouped(array $categories): array
    {
        $known = [];
        $result = [];

        foreach (self::GROUPS as $slug => $group) {
            $children = [];
            foreach ($group['children'] as $child) {
                if (isset($categories[$child])) {
                    $children[$child] = $categories[$child];
                    $known[$child] = true;
                }
            }
            if ($children !== []) {
                $result[$slug] = $group + ['available' => $children];
            }
        }
        $unmapped = array_diff_key($categories, $known);
        if ($unmapped !== []) {
            $result['additional'] = [
                'name' => 'More categories',
                'subtitle' => 'Additional classified listing categories',
                'icon' => '🗂️',
                'children' => array_keys($unmapped),
                'available' => $unmapped,
            ];
        }

        return $result;
    }

    public static function childSlugs(string $slug, array $categories): array
    {
        return array_keys(self::grouped($categories)[$slug]['available'] ?? []);
    }
}
