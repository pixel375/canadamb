<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

final class MarketplaceDetailFilters
{
    // Scalar filter names keep URLs, the API and saved searches compatible.
    public const FIELDS = [
        'make' => ['label' => 'Make / manufacturer', 'attribute' => 'make', 'categories' => ['cars', 'rvs', 'farm'], 'type' => 'text'],
        'model' => ['label' => 'Model', 'attribute' => 'model', 'categories' => ['cars', 'rvs', 'farm', 'electronics'], 'type' => 'text'],
        'brand' => ['label' => 'Brand', 'attribute' => 'brand', 'categories' => ['electronics'], 'type' => 'text'],
        'fuel' => ['label' => 'Fuel', 'attribute' => 'fuel', 'categories' => ['cars'], 'type' => 'text'],
        'transmission' => ['label' => 'Transmission', 'attribute' => 'transmission', 'categories' => ['cars'], 'type' => 'text'],
        'year_min' => ['label' => 'Earliest model year', 'attribute' => 'year', 'categories' => ['cars', 'rvs', 'farm'], 'type' => 'integer', 'min' => 1880, 'max' => 2100, 'operator' => '>='],
        'year_max' => ['label' => 'Latest model year', 'attribute' => 'year', 'categories' => ['cars', 'rvs', 'farm'], 'type' => 'integer', 'min' => 1880, 'max' => 2100, 'operator' => '<='],
        'mileage_max' => ['label' => 'Maximum mileage (km)', 'attribute' => 'mileage', 'categories' => ['cars'], 'type' => 'integer', 'min' => 0, 'max' => 9999999, 'operator' => '<='],
        'hours_max' => ['label' => 'Maximum operating hours', 'attribute' => 'hours', 'categories' => ['farm'], 'type' => 'integer', 'min' => 0, 'max' => 9999999, 'operator' => '<='],
        'length_max' => ['label' => 'Maximum length (ft)', 'attribute' => 'length', 'categories' => ['rvs'], 'type' => 'numeric', 'min' => 1, 'max' => 200, 'operator' => '<='],
    ];

    public static function rules(): array
    {
        $rules = [];
        foreach (self::FIELDS as $key => $field) {
            $rules[$key] = $field['type'] === 'text'
                ? ['nullable', 'string', 'max:80']
                : ['nullable', $field['type'], 'between:'.$field['min'].','.$field['max']];
        }

        return $rules;
    }

    public static function normalize(array $filters): array
    {
        foreach (self::FIELDS as $key => $field) {
            if (! isset($filters[$key])) {
                continue;
            }
            if (! in_array($filters['category'] ?? null, $field['categories'], true)) {
                throw ValidationException::withMessages([$key => 'Choose a compatible specific category for this filter.']);
            }
            $filters[$key] = match ($field['type']) {
                'integer' => (int) $filters[$key],
                'numeric' => (float) $filters[$key],
                default => trim($filters[$key]),
            };
            if ($filters[$key] === '') {
                unset($filters[$key]);
            }
        }
        if (isset($filters['year_min'], $filters['year_max']) && $filters['year_min'] > $filters['year_max']) {
            throw ValidationException::withMessages(['year_max' => 'Latest model year must not precede earliest model year.']);
        }

        return $filters;
    }

    public static function apply(Builder $query, array $filters): void
    {
        foreach (self::FIELDS as $key => $field) {
            if (! isset($filters[$key]) || ! in_array($filters['category'] ?? null, $field['categories'], true)) {
                continue;
            }
            $column = 'attributes->'.$field['attribute'];
            if ($field['type'] === 'text') {
                KeywordSearch::apply($query, $filters[$key], [$column]);
            } else {
                // Laravel wraps JSON selectors for the current DB driver; numeric
                // values have been validated at listing submission, including strings.
                $wrapped = $query->getQuery()->getGrammar()->wrap($column);
                $query->whereNotNull($column)->whereRaw("CAST({$wrapped} AS DECIMAL(16, 4)) {$field['operator']} ?", [$filters[$key]]);
            }
        }
    }
}
