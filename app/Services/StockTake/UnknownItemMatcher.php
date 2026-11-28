<?php

declare(strict_types=1);

namespace App\Services\StockTake;

use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductStoreStock;
use App\Models\StockTakeUnknownItem;
use App\Support\ProductSearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Finds the shop's product an unknown barcode most likely is, for "Link to
 * existing": the likeliest ones for what the counter wrote before anything
 * is typed, then by any words in any order, SKU or barcode.
 *
 * Each match carries what a manager needs to compare: photo, SKU, price and
 * how many the books say this branch has.
 */
final class UnknownItemMatcher
{
    public const SEARCH_LIMIT = 20;

    public const LIKELY_LIMIT = 8;

    /** @return list<array{id: int, name: string, sku: ?string, price: float, stock: int|float|null, image: ?string, barcode: ?string}> */
    public function likely(StockTakeUnknownItem $item): array
    {
        $words = collect(ProductSearch::words($item->name_entered))
            ->filter(fn (string $w) => mb_strlen($w) >= 3 || ctype_digit($w))
            ->unique()
            ->take(ProductSearch::MAX_WORDS)
            ->values();

        if ($words->isEmpty()) {
            return [];
        }

        $likes = $words->map(fn (string $w) => '%' . $w . '%')->all();
        $score = implode(' + ', array_fill(0, count($likes), 'CASE WHEN products.name LIKE ? THEN 1 ELSE 0 END'));

        // Most words in common first ("peak milk tin" → PEAK EVAPORATED MILK
        // before any other milk).
        $products = $this->products($item)
            ->where(function (Builder $q) use ($likes) {
                foreach ($likes as $like) {
                    $q->orWhere('products.name', 'like', $like);
                }
            })
            ->orderByRaw("({$score}) DESC", $likes)
            ->orderBy('products.name')
            ->limit(self::LIKELY_LIMIT)
            ->get();

        return $this->present($item, $products);
    }

    /** @return list<array{id: int, name: string, sku: ?string, price: float, stock: int|float|null, image: ?string, barcode: ?string}> */
    public function search(StockTakeUnknownItem $item, string $term): array
    {
        $term = trim($term);

        if ($term === '') {
            return [];
        }

        $escaped = str_replace(['%', '_'], ['\%', '\_'], $term);
        $like    = '%' . $escaped . '%';

        $products = $this->products($item)
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $words) => ProductSearch::apply($words, $term, ['products.name', 'products.sku', 'products.brand', 'products.search_keywords']))
                ->orWhere('products.barcode', 'like', $like)
                ->orWhereIn('products.id', ProductBarcode::query()
                    ->where('vendor_id', $item->vendor_id)
                    ->where('barcode', 'like', $like)
                    ->select('product_id')))
            ->orderByRaw('CASE WHEN products.name LIKE ? THEN 0 ELSE 1 END', [$escaped . '%'])
            ->orderBy('products.name')
            ->limit(self::SEARCH_LIMIT)
            ->get();

        return $this->present($item, $products);
    }

    /** This shop's own products (not copies of another shop's), with their photos. */
    private function products(StockTakeUnknownItem $item): Builder
    {
        return Product::query()
            ->select(['products.id', 'products.name', 'products.sku', 'products.price', 'products.barcode'])
            ->where('products.vendor_id', $item->vendor_id)
            ->whereNull('products.source_product_id')
            ->with('media');
    }

    /**
     * @param  Collection<int, Product>  $products
     * @return list<array{id: int, name: string, sku: ?string, price: float, stock: int|float|null, image: ?string, barcode: ?string}>
     */
    private function present(StockTakeUnknownItem $item, Collection $products): array
    {
        if ($products->isEmpty()) {
            return [];
        }

        $stock = ProductStoreStock::query()
            ->where('store_id', $item->store_id)
            ->whereIn('product_id', $products->modelKeys())
            ->pluck('quantity', 'product_id');

        return $products->map(fn (Product $p) => [
            'id'      => (int) $p->id,
            'name'    => (string) $p->name,
            'sku'     => $p->sku ?: null,
            'price'   => (float) $p->getRawOriginal('price'),
            'stock'   => isset($stock[$p->id]) ? (float) $stock[$p->id] : null,
            'image'   => $p->getFirstMediaUrl('product-images', 'thumb') ?: null,
            'barcode' => $p->getRawOriginal('barcode') ?: null,
        ])->values()->all();
    }
}
