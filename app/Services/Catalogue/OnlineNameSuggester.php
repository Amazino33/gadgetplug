<?php

namespace App\Services\Catalogue;

use App\Models\Product;
use Illuminate\Support\Str;

// Drafts a search-friendly online name from what the product row already holds.
//
// Rule-based on purpose — no AI call. Shop floors name stock by model code and
// shelf shorthand ("ITEL 2160 OLD ONE", "5627 METAL ITEL"); nobody searches
// for that. The draft is:
//
//   {brand} {model and descriptive words} {RAM} {storage} {network} {category} – {colour}
//   e.g. "itel 2160 4GB RAM 64GB Feature Phone – Black"
//
// It is a starting point the catalogue editor edits, never saved on its own.
class OnlineNameSuggester
{
    public const MAX_LENGTH = 150;

    /**
     * Shelf shorthand that means something to staff and nothing to a buyer.
     * Matched as whole words, case-insensitive.
     */
    private const NOISE_WORDS = [
        'old', 'one', 'new', 'orig', 'org', 'original', 'copy', 'grade', 'used',
        'fresh', 'boxed', 'pcs', 'pc', 'only', 'type', 'model', 'item',
    ];

    public function suggest(Product $product): string
    {
        $brand = trim((string) $product->brand);
        $specs = $this->specs($product);

        $words = $this->descriptiveWords((string) $product->name, $brand);

        $parts = array_filter([
            $brand,
            implode(' ', $words),
            $specs['ram'] ? $specs['ram'].' RAM' : null,
            $specs['storage'],
            $specs['network'],
            $this->categoryNoun($product, $words),
        ]);

        $name = preg_replace('/\s+/', ' ', trim(implode(' ', $parts)));

        if ($specs['colour']) {
            $name .= ' – '.$specs['colour'];
        }

        return Str::limit($name, self::MAX_LENGTH, '');
    }

    /**
     * The internal name minus the brand (it leads the draft already) and the
     * shelf shorthand. Model codes are kept exactly as written; shouted words
     * are brought down to normal capitalisation.
     *
     * @return array<int, string>
     */
    private function descriptiveWords(string $name, string $brand): array
    {
        $tokens = preg_split('/[\s\/_]+/', trim($name)) ?: [];
        $brandLower = Str::lower($brand);

        $words = [];

        foreach ($tokens as $token) {
            $clean = trim($token, " \t-,.()");
            $lower = Str::lower($clean);

            if ($clean === '' || $lower === $brandLower || in_array($lower, self::NOISE_WORDS, true)) {
                continue;
            }

            // Model codes (anything with a digit) stay exactly as written.
            $words[] = preg_match('/\d/', $clean) ? $clean : Str::ucfirst($lower);
        }

        return array_values(array_unique($words));
    }

    /**
     * RAM, storage, network and colour from the specifications key/value list,
     * whatever the vendor called the keys.
     *
     * @return array{ram: ?string, storage: ?string, network: ?string, colour: ?string}
     */
    private function specs(Product $product): array
    {
        $found = ['ram' => null, 'storage' => null, 'network' => null, 'colour' => null];

        foreach ((array) ($product->specifications ?? []) as $key => $value) {
            $key = Str::lower((string) $key);
            $value = trim((string) $value);

            if ($value === '') {
                continue;
            }

            // Whole words: "Frame" is not RAM and "Chrome" is not ROM.
            match (true) {
                (bool) preg_match('/\b(ram|memory)\b/', $key)              => $found['ram'] ??= $value,
                (bool) preg_match('/\b(storage|rom|capacity)\b/', $key)    => $found['storage'] ??= $value,
                (bool) preg_match('/\b(network|sim|connectivity)\b/', $key) => $found['network'] ??= $value,
                (bool) preg_match('/\b(colou?r)\b/', $key)                 => $found['colour'] ??= Str::title($value),
                default                                                     => null,
            };
        }

        return $found;
    }

    /**
     * The category as a single noun ("Feature Phones" → "Feature Phone"), when
     * it reads as one and the name doesn't already say it.
     *
     * @param  array<int, string>  $words
     */
    private function categoryNoun(Product $product, array $words): ?string
    {
        $category = trim((string) $product->category?->name);

        // "Phones & Tablets" or "Accessories and Cables" is a shelf, not a noun.
        if ($category === '' || preg_match('/&|\band\b|,/i', $category)) {
            return null;
        }

        $noun = Str::singular($category);

        foreach ($words as $word) {
            if (Str::contains(Str::lower($noun), Str::lower($word)) || Str::contains(Str::lower($word), Str::lower($noun))) {
                return null;
            }
        }

        return $noun;
    }
}
