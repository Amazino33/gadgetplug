<?php

declare(strict_types=1);

namespace App\Services\Assistant;

use App\Actions\Assistant\Queries\BaseAssistantQuery;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductUnit;
use App\Models\Store;
use App\Support\Quantity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Products as the assistant reads them, the way the Products screen does.
 *
 * One home store per product: a product row belongs to one branch
 * (products.store_id), and its stock is that branch's product_store_stock
 * row, falling back to the product's own figure when the row is missing,
 * the same COALESCE the dashboard uses. The same item at two branches is
 * two rows, each with its own price and stock; they are grouped back
 * together by exact normalized name. Phase 0 decision 6 said exact SKU
 * first, but a SKU is unique within a business (vendor_id + sku), so two
 * branches' rows never share one: the name is the only key that can join
 * them. Looser matches are only ever offered as "possible" matches.
 *
 * Quantities keep their halves (Quantity), and resold (linked) listings and
 * services have no stock of their own, so they are never "out of stock".
 */
final class ProductCatalogue
{
    /** Groups returned per question: enough to choose from, short enough to read. */
    public const MAX_GROUPS = 8;

    /** Stock on hand less reserved at the product's own branch. */
    public const AVAILABLE_SQL = '(CAST(COALESCE(pss.quantity, products.stock_quantity) AS DECIMAL(12,3)) - CAST(COALESCE(pss.reserved, products.reserved_stock) AS DECIMAL(12,3)))';

    /** Products homed at these stores, with their own branch's stock selected. */
    public static function query(array $storeIds): Builder
    {
        $storeIds = array_values(array_map('intval', $storeIds));

        return Product::query()
            ->leftJoin('product_store_stock as pss', function ($join) {
                $join->on('pss.product_id', '=', 'products.id')
                    ->on('pss.store_id', '=', 'products.store_id');
            })
            ->whereIn('products.store_id', $storeIds)
            // Belt and braces: the stores' own businesses only.
            ->whereIn('products.vendor_id', Store::query()->whereIn('id', $storeIds)->select('vendor_id'))
            ->select('products.*')
            ->selectRaw('COALESCE(pss.quantity, products.stock_quantity) as store_quantity')
            ->selectRaw('COALESCE(pss.reserved, products.reserved_stock) as store_reserved');
    }

    /** On sale, and stock is ours to count (not a resold listing, not a service). */
    public static function tracked(Builder $query): Builder
    {
        return $query->published()->ownStock()->where('products.is_service', false);
    }

    /**
     * Finds products by code (SKU, barcode, a unit's SKU, an extra barcode),
     * else by name word for word (the storefront's own search: name, online
     * name, brand, category), else loosely ("20000 mah" for "20000mAh").
     *
     * @return array{match: string, total: int, groups: array<int, array<string, mixed>>}
     */
    public static function find(array $storeIds, string $term, bool $inStockOnly = false): array
    {
        $term = trim(mb_substr($term, 0, 100));
        $base = fn () => self::query($storeIds)
            ->when($inStockOnly, fn (Builder $q) => $q->whereRaw(self::AVAILABLE_SQL.' > 0'))
            ->with(['store:id,name', 'units' => fn ($q) => $q->where('is_active', true)->where('is_base', false)->orderBy('sort_order')]);

        $match = 'exact_code';
        $products = $base()
            ->where(fn (Builder $q) => $q->where('products.sku', $term)
                ->orWhere('products.barcode', $term)
                ->orWhereIn('products.id', ProductBarcode::query()->where('barcode', $term)->select('product_id'))
                ->orWhereIn('products.id', ProductUnit::query()->where('sku', $term)->select('product_id')))
            ->limit(100)
            ->get();

        // A code names one branch's row; the same item at the other branches
        // has its own SKU, so bring those in by name.
        if ($products->isNotEmpty()) {
            $keys = $products->map(fn (Product $p) => self::matchKey($p))->unique()->all();
            $siblings = $base()
                ->whereNotIn('products.id', $products->pluck('id'))
                ->where(function (Builder $q) use ($products) {
                    foreach ($products->pluck('name')->unique() as $name) {
                        $q->orWhere('products.name', 'like', '%'.addcslashes(trim((string) $name), '%_\\').'%');
                    }
                })
                ->limit(100)
                ->get()
                ->filter(fn (Product $p) => in_array(self::matchKey($p), $keys, true));

            $products = $products->concat($siblings);
        }

        if ($products->isEmpty()) {
            $match = 'name';
            $products = $base()->searchCatalogue(self::stemmed($term))->limit(100)->get();
        }

        if ($products->isEmpty()) {
            $match = 'possible';
            $products = self::loose($base(), $term)->limit(100)->get();
        }

        $groups = self::group($products);

        return [
            'match' => $products->isEmpty() ? 'none' : $match,
            'total' => $groups->count(),
            'groups' => $groups->take(self::MAX_GROUPS)->values()->all(),
        ];
    }

    /** The key two branches' rows share when they are the same item (see the class note). */
    public static function matchKey(Product $product): string
    {
        return self::normalizedName((string) $product->name);
    }

    /** Case, spacing and surrounding space ignored; nothing else. */
    public static function normalizedName(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));
    }

    /** The branch's available quantity for a row selected through query(). */
    public static function available(Product $product): int|float
    {
        return max(0, Quantity::sub($product->getAttribute('store_quantity') ?? 0, $product->getAttribute('store_reserved') ?? 0));
    }

    /**
     * @param  Collection<int, Product>  $products
     * @return Collection<int, array<string, mixed>>
     */
    private static function group(Collection $products): Collection
    {
        return $products
            ->groupBy(fn (Product $p) => self::matchKey($p))
            ->map(function (Collection $rows) {
                $first = $rows->first();
                $branches = $rows->map(fn (Product $p) => self::branchRow($p))->values();
                $stocked = $branches->filter(fn (array $b) => array_key_exists('available', $b));
                $prices = $branches->pluck('price')->unique()->values();

                return array_filter([
                    'name' => (string) $first->name,
                    'brand' => $first->brand ?: null,
                    // One price when every branch charges the same; else see each branch.
                    ...($prices->count() === 1 ? BaseAssistantQuery::naira('price', (float) $prices->first()) : ['price_differs_by_branch' => true]),
                    'total_available' => $stocked->isNotEmpty() ? Quantity::of($stocked->sum('available')) : null,
                    'branches' => $branches->all(),
                ], fn ($v) => $v !== null);
            })
            // Most stock first, so "do we have it" reads top-down.
            ->sortByDesc(fn (array $g) => $g['total_available'] ?? -1)
            ->values();
    }

    /** @return array<string, mixed> */
    private static function branchRow(Product $product): array
    {
        $row = [
            'store' => (string) ($product->store?->name ?? 'Unknown store'),
            'sku' => $product->sku ?: null,
            ...BaseAssistantQuery::naira('price', (float) $product->price),
        ];

        if ($product->wholesale_price !== null && (float) $product->wholesale_price > 0) {
            $row += BaseAssistantQuery::naira('wholesale_price', (float) $product->wholesale_price);
        }

        // Cost is removed by CostFieldStripper for anyone without view_cost_price.
        if ($product->cost_price !== null) {
            $row += BaseAssistantQuery::naira('cost_price', (float) $product->cost_price);
        }

        if ($product->isLinked()) {
            $row['resold'] = true; // stock is the supplier's, not ours
        } elseif ($product->is_service) {
            $row['service'] = true;
        } else {
            $row['available'] = self::available($product);
            $row['low_stock'] = $row['available'] > 0 && $row['available'] < (int) $product->low_stock_threshold;
        }

        if (! self::isOnSale($product)) {
            $row['not_on_sale'] = true; // draft, archived or scheduled
        }

        if ($product->relationLoaded('units') && $product->units->isNotEmpty()) {
            $row['units'] = $product->units->map(fn (ProductUnit $u) => [
                'unit' => (string) $u->name,
                'contains' => (int) $u->conversion_factor,
                ...BaseAssistantQuery::naira('price', (float) $u->price),
            ])->all();
        }

        return $row;
    }

    private static function isOnSale(Product $product): bool
    {
        return $product->status === 'published'
            && ($product->published_at === null || $product->published_at->lte(now()))
            && ($product->unpublish_at === null || $product->unpublish_at->gt(now()));
    }

    /** "power banks" also finds "Power Bank": each word loses a plural s. */
    private static function stemmed(string $term): string
    {
        $words = preg_split('/\s+/u', $term, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return implode(' ', array_map(
            fn (string $w) => mb_strlen($w) > 3 && preg_match('/[^s]s$/iu', $w) ? mb_substr($w, 0, -1) : $w,
            $words,
        ));
    }

    /** Each word, spaces and dashes and dots ignored, somewhere in the name, online name or brand. */
    private static function loose(Builder $query, string $term): Builder
    {
        $collapse = fn (string $column) => "LOWER(REPLACE(REPLACE(REPLACE(COALESCE({$column}, ''), ' ', ''), '-', ''), '.', ''))";
        $words = preg_split('/\s+/u', self::stemmed($term), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach (array_slice($words, 0, 6) as $word) {
            $needle = '%'.addcslashes(mb_strtolower((string) preg_replace('/[\s.\-]+/u', '', $word)), '%_\\').'%';

            $query->where(fn (Builder $q) => $q
                ->whereRaw($collapse('products.name').' LIKE ?', [$needle])
                ->orWhereRaw($collapse('products.online_name').' LIKE ?', [$needle])
                ->orWhereRaw($collapse('products.brand').' LIKE ?', [$needle]));
        }

        return $words === [] ? $query->whereRaw('1 = 0') : $query;
    }
}
