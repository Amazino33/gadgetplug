<?php

declare(strict_types=1);

namespace App\Services\Purchases;

use App\Support\Quantity;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductStoreStock;
use App\Models\ProductUnit;
use App\Models\Store;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Support\ProductSearch;
use App\Services\Products\SalesPopularity;
use App\Services\Products\SearchShortcuts;

/**
 * The products the Purchase Terminal can receive, and the shape it sees them in.
 *
 * Own stock only: a linked listing holds no stock of its own, and a service
 * holds none at all, so neither is something a delivery can put on a shelf.
 */
class PurchaseCatalogue
{
    /**
     * What a scanned code names, barcode first and SKU second.
     *
     * A barcode can name a bigger unit — a carton's own code — and when it
     * does, that is the unit the line goes in as. Returned as [product, unitId].
     *
     * @return array{0: Product, 1: int|null}|null
     */
    public function lookup(Vendor $vendor, string $code): ?array
    {
        $code = trim($code);

        if ($code === '') {
            return null;
        }

        $product = $this->receivable($vendor)->where('barcode', $code)->first();

        if ($product) {
            return [$product, null];
        }

        $barcode = ProductBarcode::query()
            ->where('vendor_id', $vendor->id)
            ->where('barcode', $code)
            ->first();

        if ($barcode) {
            $product = $this->receivable($vendor)->whereKey($barcode->product_id)->first();

            if ($product) {
                return [$product, $barcode->product_unit_id ? (int) $barcode->product_unit_id : null];
            }
        }

        $product = $this->receivable($vendor)->where('sku', $code)->first();

        return $product ? [$product, null] : null;
    }

    /**
     * One page of the whole catalogue, for the terminal to hold in memory and
     * scan against without asking the server — the till's own approach (see
     * PosProductController::index): pages in id order, small enough that a
     * shop of thousands never builds one huge response.
     *
     * Plain rows rather than models, and only what a scan needs, so a page of
     * 500 costs a few hundred KB and no model hydration.
     *
     * @return array<int, array<string, mixed>>
     */
    public function page(Vendor $vendor, int $storeId, int $afterId, int $limit): array
    {
        $products = DB::table('products')
            ->where('vendor_id', $vendor->id)
            ->whereNull('source_product_id')
            ->where(fn ($q) => $q->where('is_service', false)->orWhereNull('is_service'))
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'name', 'brand', 'search_keywords', 'sku', 'barcode', 'price', 'cost_price', 'base_unit', 'track_serial']);

        if ($products->isEmpty()) {
            return [];
        }

        $ids = $products->pluck('id')->all();

        $units = DB::table('product_units')
            ->whereIn('product_id', $ids)
            ->where('is_active', true)
            ->orderBy('conversion_factor')
            ->get(['id', 'product_id', 'name', 'conversion_factor', 'is_base', 'price'])
            ->groupBy('product_id');

        $codes = DB::table('product_barcodes')
            ->whereIn('product_id', $ids)
            ->get(['product_id', 'product_unit_id', 'barcode'])
            ->groupBy('product_id');

        $stock = $this->stockAt($storeId, $ids);
        $sold  = app(SalesPopularity::class)->forStore($vendor->id, $storeId);
        $learned = app(SearchShortcuts::class)->learnedFor($ids);

        return $products->map(function ($p) use ($units, $codes, $stock, $sold, $learned) {
            $productCodes = ($codes[$p->id] ?? collect());

            return [
                'id'           => (int) $p->id,
                'name'         => $p->name,
                'brand'        => $p->brand,
                // How often the branch sold it lately: search shows the
                // everyday item first.
                'sold_30d'     => $sold[$p->id] ?? 0,
                'search_terms' => SearchShortcuts::terms($p->search_keywords, $learned[$p->id] ?? []),
                'sku'          => $p->sku,
                'barcode'      => $p->barcode,
                'base_unit'    => $p->base_unit ?: 'Piece',
                'price'        => (float) $p->price,
                'cost_price'   => $p->cost_price !== null ? (float) $p->cost_price : null,
                'track_serial' => (bool) $p->track_serial,
                'stock'        => Quantity::of($stock[$p->id] ?? 0),
                'units'        => ($units[$p->id] ?? collect())->map(fn ($u) => [
                    'id'            => (int) $u->id,
                    'name'          => $u->name,
                    'factor'        => max(1, (int) $u->conversion_factor),
                    'is_base'       => (bool) $u->is_base,
                    'selling_price' => (float) $u->price,
                    'barcodes'      => $productCodes->where('product_unit_id', $u->id)->pluck('barcode')->values(),
                ])->values(),
                // Every code that names this product, and which unit it means
                // (null: the base unit). The terminal keys its scan map on these.
                'barcodes'     => $productCodes->map(fn ($b) => [
                    'barcode' => $b->barcode,
                    'unit_id' => $b->product_unit_id !== null ? (int) $b->product_unit_id : null,
                ])->values(),
            ];
        })->all();
    }

    /** @return Collection<int, Product> */
    public function search(Vendor $vendor, string $term, int $limit = 15): Collection
    {
        $term = trim($term);

        if (mb_strlen($term) < 2) {
            return collect();
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
        $sold = app(SalesPopularity::class)->forStore($vendor->id, null);

        return $this->receivable($vendor)
            ->where(fn (Builder $q) => $q
                // Every typed word, in any order (see App\Support\ProductSearch).
                ->where(fn (Builder $words) => ProductSearch::apply($words, $term, ['name', 'sku', 'brand', 'search_keywords']))
                // A word staff taught it (see SearchShortcuts).
                ->orWhereIn('id', SearchShortcuts::productIdsMatching($vendor->id, $term))
                ->orWhere('barcode', 'like', $like))
            ->orderByRaw('CASE WHEN name LIKE ? THEN 0 ELSE 1 END', [str_replace(['%', '_'], ['\%', '\_'], $term).'%'])
            ->orderBy('name')
            // More than are sent, so those sent are the likeliest: a name
            // starting with what was typed, then what the shop sells most.
            ->limit($limit * 3)
            ->get()
            ->sortBy(fn (Product $p) => [
                str_starts_with(mb_strtolower($p->name), mb_strtolower($term)) ? 0 : 1,
                -($sold[$p->id] ?? 0),
                $p->name,
            ])
            ->take($limit)
            ->values();
    }

    /**
     * A product made on the spot, because the scanned barcode named nothing.
     *
     * Published and on the till straight away — it is being received so it can
     * be sold — but not online, where a product with no picture has no place.
     *
     * @param  array{name: string, barcode?: string|null, base_unit?: string|null, price: float|int|string}  $data
     */
    public function quickCreate(Vendor $vendor, Store $store, array $data): Product
    {
        return Product::create([
            'vendor_id'   => $vendor->id,
            'store_id'    => $store->id,
            'category_id' => $this->uncategorised(),
            'name'        => Str::squish($data['name']),
            'barcode'     => filled($data['barcode'] ?? null) ? trim((string) $data['barcode']) : null,
            'base_unit'   => filled($data['base_unit'] ?? null) ? Str::squish((string) $data['base_unit']) : 'Piece',
            'price'       => (float) $data['price'],
            'status'      => 'published',
            'show_in_pos' => true,
            'show_online' => false,
        ])->fresh(['units']);
    }

    /**
     * What each product holds on the shelf at one branch, in base units, in a
     * single query — the search list formats up to fifteen at once.
     *
     * @param  array<int, int>  $productIds
     * @return array<int, int>
     */
    public function stockAt(int $storeId, array $productIds): array
    {
        return ProductStoreStock::query()
            ->where('store_id', $storeId)
            ->whereIn('product_id', $productIds)
            ->pluck('quantity', 'product_id')
            ->map(fn ($q) => (int) $q)
            ->all();
    }

    /**
     * @param  int|null  $storeId  the branch whose stock to report; null reports the vendor-wide figure
     * @param  array<int, int>|null  $stock  preloaded from stockAt(), to save a query per product
     * @return array<string, mixed>
     */
    public function format(Product $product, ?int $scannedUnitId = null, ?int $storeId = null, ?array $stock = null): array
    {
        $product->loadMissing(['units', 'barcodes']);

        $units = $product->units
            ->where('is_active', true)
            ->sortBy('conversion_factor')
            ->map(fn (ProductUnit $u) => [
                'id'            => (int) $u->id,
                'name'          => $u->name,
                'factor'        => max(1, (int) $u->conversion_factor),
                'is_base'       => (bool) $u->is_base,
                'selling_price' => (float) $u->price,
                'barcodes'      => $product->barcodes->where('product_unit_id', $u->id)->pluck('barcode')->values(),
            ])
            ->values();

        return [
            'id'              => $product->id,
            'name'            => $product->name,
            'brand'           => $product->brand,
            'sold_30d'        => app(SalesPopularity::class)->forStore((int) $product->vendor_id, $storeId)[$product->id] ?? 0,
            'search_terms'    => SearchShortcuts::terms($product->search_keywords, app(SearchShortcuts::class)->learnedFor([$product->id])[$product->id] ?? []),
            'sku'             => $product->sku,
            'barcode'         => $product->barcode,
            'base_unit'       => $product->base_unit ?: 'Piece',
            'price'           => (float) $product->price,
            'cost_price'      => $product->cost_price !== null ? (float) $product->cost_price : null,
            'track_serial'    => (bool) $product->track_serial,
            // What is left on the shelf right now, in base units, at the branch
            // being received into. Negative when the till sold ahead of stock.
            'stock'           => $storeId === null
                ? Quantity::of($product->stock_quantity)
                : (int) (($stock ?? $this->stockAt($storeId, [$product->id]))[$product->id] ?? 0),
            'units'           => $units,
            'barcodes'        => $product->barcodes->map(fn ($b) => [
                'barcode' => $b->barcode,
                'unit_id' => $b->product_unit_id !== null ? (int) $b->product_unit_id : null,
            ])->values(),
            'scanned_unit_id' => $scannedUnitId,
        ];
    }

    /** Products a delivery can be received into, for this vendor. */
    public function receivable(Vendor $vendor): Builder
    {
        return Product::query()
            ->where('vendor_id', $vendor->id)
            ->whereNull('source_product_id')
            ->where(fn (Builder $q) => $q->where('is_service', false)->orWhereNull('is_service'))
            ->with(['units', 'barcodes']);
    }

    private function uncategorised(): int
    {
        $existing = Category::whereRaw('LOWER(name) = ?', ['uncategorised'])->value('id');

        if ($existing !== null) {
            return (int) $existing;
        }

        $slug = 'uncategorised';
        $n    = 2;

        while (Category::where('slug', $slug)->exists()) {
            $slug = 'uncategorised-'.$n++;
        }

        return Category::create(['name' => 'Uncategorised', 'slug' => $slug, 'is_active' => true])->id;
    }
}
