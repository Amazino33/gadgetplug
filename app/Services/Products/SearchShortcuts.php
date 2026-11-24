<?php

namespace App\Services\Products;

use App\Models\Product;
use App\Models\ProductSearchShortcut;
use App\Support\ProductSearch;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * The shop's own words for its products, and the ones staff teach it.
 *
 * Two sources, searched the same way by the till and the Purchase Terminal:
 *
 *   - Search keywords, typed on the product by whoever manages it
 *     ("coke, coca" on COCA-COLA 50CL).
 *   - Learned shortcuts. When a search for "titus" finds nothing and the
 *     cashier then finds and picks MACKEREL FISH, or picks a product that was
 *     not the top result, the browser reports the pair. Once the same pair has
 *     been reported MIN_HITS times it counts: the next "titus" puts the
 *     mackerel first, on every till in the shop.
 *
 * MIN_HITS keeps one mistaken pick from teaching anything. A manager can
 * remove a learned word from the product's pop-up on the Products list.
 */
class SearchShortcuts
{
    public const MIN_HITS = 2;

    /** Learned words sent per product, most used first. */
    private const PER_PRODUCT = 10;

    /** "titus" — or null when the text is not worth learning (a code, a sentence). */
    public static function clean(?string $term): ?string
    {
        $words = ProductSearch::words($term);
        $text = implode(' ', $words);

        if (mb_strlen($text) < 2 || mb_strlen($text) > 60 || count($words) > 4) {
            return null;
        }

        // A barcode or a number is a code, not a word for something.
        if (! preg_match('/[a-z]/', $text)) {
            return null;
        }

        return $text;
    }

    /** Counts one more time staff searched $term and picked this product. */
    public function record(int $vendorId, int $productId, ?string $term): bool
    {
        $term = self::clean($term);

        if ($term === null || ! Product::where('vendor_id', $vendorId)->whereKey($productId)->exists()) {
            return false;
        }

        $now = now();

        ProductSearchShortcut::upsert(
            [[
                'vendor_id'    => $vendorId,
                'product_id'   => $productId,
                'term'         => $term,
                'hits'         => 1,
                'last_used_at' => $now,
                'created_at'   => $now,
                'updated_at'   => $now,
            ]],
            ['vendor_id', 'term', 'product_id'],
            ['hits' => DB::raw('hits + 1'), 'last_used_at' => $now, 'updated_at' => $now],
        );

        return true;
    }

    /** The keywords typed on a product, as a list. */
    public static function keywords(?string $text): array
    {
        return collect(preg_split('/[,;\n]+/', (string) $text))
            ->map(fn ($k) => trim($k))
            ->filter(fn ($k) => $k !== '')
            ->unique(fn ($k) => mb_strtolower($k))
            ->values()
            ->all();
    }

    /**
     * Learned words that count, for a page of products.
     *
     * @param  array<int, int>  $productIds
     * @return array<int, array<int, array{term: string, hits: int}>>
     */
    public function learnedFor(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        return ProductSearchShortcut::query()
            ->whereIn('product_id', $productIds)
            ->where('hits', '>=', self::MIN_HITS)
            ->orderByDesc('hits')
            ->get(['product_id', 'term', 'hits'])
            ->groupBy('product_id')
            ->map(fn ($rows) => $rows->take(self::PER_PRODUCT)->map(fn ($r) => ['term' => $r->term, 'hits' => $r->hits])->values()->all())
            ->all();
    }

    /**
     * What the devices search a product by besides its name: its keywords
     * and the learned words that count. The payload key is search_terms.
     *
     * @param  array<int, array{term: string, hits: int}>  $learned
     */
    public static function terms(?string $keywords, array $learned = []): array
    {
        return [
            ...array_map(fn ($k) => ['term' => $k, 'hits' => 0], self::keywords($keywords)),
            ...$learned,
        ];
    }

    /** Product ids a learned word starting with $term points at — for the servers' own searches. */
    public static function productIdsMatching(int $vendorId, string $term): QueryBuilder
    {
        $clean = implode(' ', ProductSearch::words($term));

        return DB::table('product_search_shortcuts')
            ->select('product_id')
            ->where('vendor_id', $vendorId)
            ->where('hits', '>=', self::MIN_HITS)
            ->when($clean === '', fn ($q) => $q->whereRaw('1 = 0'), fn ($q) => $q->where('term', 'like', $clean.'%'));
    }

    /** Everything learned for one product, used or not yet counting — for the product's own page. */
    public function allFor(Product $product): array
    {
        return ProductSearchShortcut::where('product_id', $product->id)
            ->orderByDesc('hits')
            ->get(['term', 'hits'])
            ->map(fn ($r) => ['term' => $r->term, 'hits' => $r->hits])
            ->all();
    }

    /** Forgets learned words a manager removed. */
    public function forget(Product $product, array $terms): void
    {
        if ($terms === []) {
            return;
        }

        ProductSearchShortcut::where('product_id', $product->id)->whereIn('term', $terms)->delete();
    }
}
