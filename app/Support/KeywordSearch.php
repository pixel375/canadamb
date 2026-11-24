<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

final class KeywordSearch
{
    /**
     * Each word must match at least one public text field. Keep the groups
     * nested so an OR can never bypass a module's visibility or location scope.
     * Columns are fixed by the calling controller, never supplied by visitors.
     *
     * @param  list<string>  $columns
     */
    public static function apply(Builder $query, string $term, array $columns): Builder
    {
        foreach (self::patterns($term) as $pattern) {
            $query->where(function (Builder $match) use ($columns, $pattern) {
                foreach ($columns as $column) {
                    $wrapped = $match->getQuery()->getGrammar()->wrap($column);
                    $match->orWhereRaw("LOWER({$wrapped}) LIKE ? ESCAPE '!'", [$pattern]);
                }
            });
        }

        return $query;
    }

    /** @return list<string> */
    public static function patterns(string $term): array
    {
        $words = preg_split('/\s+/u', mb_strtolower(trim($term)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_map(
            static fn (string $word): string => '%'.strtr($word, ['!' => '!!', '%' => '!%', '_' => '!_']).'%',
            $words,
        )));
    }
}
