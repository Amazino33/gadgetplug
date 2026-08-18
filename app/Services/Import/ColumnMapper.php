<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Support\Import\ProductField;

/**
 * Guesses which product field each column of an uploaded file holds.
 *
 * The guess is a starting point, never the decision - the vendor confirms or
 * overrides every column on the mapping screen before a single row is written.
 * Getting it right for common exports just means most vendors press Continue
 * instead of filling in seventeen dropdowns.
 *
 * Nothing here knows about Aronium specifically. It matches normalised header
 * text against the alias sets on ProductField, so a POS nobody has heard of maps
 * correctly the moment its header happens to be spelled like anybody else's.
 */
class ColumnMapper
{
    /**
     * Reduce a header to its comparable core: "Reorder Point", "reorder_point"
     * and "ReorderPoint" all collapse to "reorderpoint".
     */
    public static function normalise(string $header): string
    {
        $header = preg_replace('/([a-z])([A-Z])/', '$1 $2', trim($header)) ?? $header;

        return preg_replace('/[^a-z0-9]/', '', strtolower($header)) ?? '';
    }

    /**
     * @param  array<int, string>  $headers
     * @return array<string, string>  header => ProductField value, omitting unmatched headers
     */
    public function guess(array $headers): array
    {
        $mapping = [];
        $taken   = [];

        // Two passes so an exact hit always beats a partial one. Without this,
        // a file with both "Description" and "Long Description" could hand
        // Description to whichever column happened to come first.
        foreach ([true, false] as $exactOnly) {
            foreach ($headers as $header) {
                if ($header === '' || isset($mapping[$header])) {
                    continue;
                }

                $field = $this->match($header, $taken, $exactOnly);

                if ($field === null) {
                    continue;
                }

                $mapping[$header] = $field->value;
                $taken[]          = $field->value;
            }
        }

        return $mapping;
    }

    /**
     * @param  array<int, string>  $taken  fields already claimed by an earlier column
     */
    private function match(string $header, array $taken, bool $exactOnly): ?ProductField
    {
        $needle = self::normalise($header);

        if ($needle === '') {
            return null;
        }

        foreach (ProductField::importable() as $field) {
            if (in_array($field->value, $taken, true)) {
                continue;
            }

            $aliases = array_map(
                self::normalise(...),
                [$field->label(), ...$field->aliases()],
            );

            if (in_array($needle, $aliases, true)) {
                return $field;
            }

            if ($exactOnly) {
                continue;
            }

            // Partial matching is deliberately one-directional: a header may be
            // longer than the alias ("Selling Price (NGN)" contains "sellingprice"),
            // but a two-character header must not claim a field because some
            // alias happens to contain those letters.
            foreach ($aliases as $alias) {
                if (strlen($alias) >= 4 && str_contains($needle, $alias)) {
                    return $field;
                }
            }
        }

        return null;
    }
}
