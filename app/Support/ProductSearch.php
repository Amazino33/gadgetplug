<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * Word-by-word product search, the server's half of
 * resources/js/shared/productSearch.js.
 *
 * Matching the whole typed phrase ("name LIKE %peak 400%") misses the way
 * staff actually type: two short pieces, in any order. So the query is cleaned
 * the same way the browser cleans it and split into words, and a product
 * matches when EVERY word appears in its name, SKU, brand or shop words.
 *
 * The server only finds; the browser ranks. A word here may match inside a
 * word ("ola" in COLA) where the browser insists on word starts — the server
 * is deliberately the looser of the two, because the till drops a product it
 * has cached when the server's answer leaves it out.
 */
final class ProductSearch
{
    /** More than this many words is a sentence, not a search: the rest are ignored. */
    public const MAX_WORDS = 6;

    /** "Coca-Cola 50CL" → ['coca', 'cola', '50', 'cl']. Mirrors normalize() in the browser. */
    public static function words(?string $text): array
    {
        $s = (string) $text;

        // Accents dropped where the intl extension is installed; without it an
        // accented letter simply splits the word, which still matches.
        if (class_exists(\Normalizer::class) && preg_match('/[^\x00-\x7f]/', $s)) {
            $s = \Normalizer::normalize($s, \Normalizer::FORM_KD) ?: $s;
            $s = preg_replace('/\p{Mn}+/u', '', $s);
        }

        $s = mb_strtolower($s);
        $s = str_replace(["'", '`', "\u{2019}"], '', $s);
        $s = str_replace('&', ' and ', $s);
        $s = preg_replace('/(\d)([a-z])/', '$1 $2', $s);
        $s = preg_replace('/([a-z])(\d)/', '$1 $2', $s);
        $s = trim(preg_replace('/[^a-z0-9]+/', ' ', $s));

        return $s === '' ? [] : explode(' ', $s);
    }

    /**
     * Narrows a product query to those matching every word of $term.
     *
     * @param  array<int, string>  $columns  text columns a word may appear in
     */
    public static function apply(Builder $query, string $term, array $columns): Builder
    {
        $words = array_slice(array_values(array_unique(self::words($term))), 0, self::MAX_WORDS);

        foreach ($words as $word) {
            $like = '%'.$word.'%';

            $query->where(function (Builder $q) use ($columns, $like) {
                foreach ($columns as $column) {
                    $q->orWhere($column, 'like', $like);
                }
            });
        }

        return $query;
    }
}
