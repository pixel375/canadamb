<?php

namespace App\Support;

use App\Models\ListingCategory;
use App\Models\Location;
use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class MarketplaceSearch
{
    public const SORT_OPTIONS = [
        'newest' => 'Newest',
        'price_asc' => 'Price: low to high',
        'price_desc' => 'Price: high to low',
    ];

    public static function filters(Request $request, Site $site, array $categoryGroups): array
    {
        $priceRules = ['bail', 'nullable', 'numeric', 'decimal:0,2', 'between:0,9999999.99'];
        $rules = [
            'q' => 'nullable|string|max:100',
            'category' => 'bail|nullable|string|max:64|exists:listing_categories,slug',
            'group' => ['nullable', 'string', Rule::in(array_keys($categoryGroups))],
            'view' => 'nullable|in:all',
            'condition' => ['nullable', 'string', Rule::in(array_keys(Marketplace::CONDITIONS))],
            'min_price' => $priceRules,
            'max_price' => [...$priceRules, Rule::when($request->filled('min_price'), 'gte:min_price')],
            'sort' => ['nullable', 'string', Rule::in(array_keys(self::SORT_OPTIONS))],
        ];
        if ($site->isNetwork()) {
            $rules['location'] = ['bail', 'nullable', 'string', 'max:100', Rule::exists(Location::class, 'slug')->where('province', 'MB')->where('selectable', true)];
        }
        $filters = array_filter($request->validate($rules), fn ($value) => $value !== null && $value !== '');
        if (($filters['sort'] ?? null) === 'newest') {
            unset($filters['sort']);
        }
        // A direct leaf-category link always defines its own navigation group.
        // Stale URL/form parameters must not mislabel results with another parent.
        if (isset($filters['category'])) {
            unset($filters['group']);
        }

        return $filters;
    }

    public static function matching(Site $site, array $filters, ?array $categories = null): Builder
    {
        $categories ??= ListingCategory::pluck('name', 'slug')->all();
        $selectedGroup = $filters['group'] ?? null;
        $query = app(Marketplace::class)->visible($site)
            ->when($selectedGroup !== null && ! isset($filters['category']),
                fn ($query) => $query->whereIn('category', MarketplaceCategories::childSlugs($selectedGroup, $categories)))
            ->when(isset($filters['q']), fn ($query) => KeywordSearch::apply($query, $filters['q'], ['title', 'description']))
            ->when($filters['category'] ?? null, fn ($query, $value) => $query->where('category', $value))
            ->when($filters['condition'] ?? null, fn ($query, $value) => $query->where('condition', $value))
            ->when(isset($filters['min_price']), fn ($query) => $query->where('price_cents', '>=', (int) round((float) $filters['min_price'] * 100)))
            ->when(isset($filters['max_price']), fn ($query) => $query->where('price_cents', '<=', (int) round((float) $filters['max_price'] * 100)))
            ->when(isset($filters['location']), fn ($query) => $query->whereHas('location', fn ($location) => $location->selectable()->where('slug', $filters['location'])));
        if (isset($filters['sort'])) {
            $query->orderBy('price_cents', $filters['sort'] === 'price_asc' ? 'asc' : 'desc');
        }

        return $query;
    }
}
