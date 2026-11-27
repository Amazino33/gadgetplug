<?php

namespace App\Services\Products;

use App\Models\Product;
use App\Models\Vendor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * The Products list's data, the whole branch at once, so the page can search,
 * filter, sort and page with no trip to the server.
 *
 * Rows are compact arrays (see COLUMNS) to keep 8,000 products small: about
 * 1-2 MB of JSON, a fraction of that gzipped. Built once per version and
 * cached; version() is a few cheap aggregates the page asks for every minute
 * to learn whether anything changed.
 *
 * Cost prices are in it only for someone allowed to see them.
 */
class ProductCatalogue
{
    /** What each position in a row holds; the page reads rows by this list. */
    public const COLUMNS = [
        'id', 'name', 'sku', 'barcode', 'category', 'brand', 'status', 'price', 'cost',
        'available', 'quantity', 'low_at', 'photo', 'created', 'last_sold', 'sold_7d', 'search', 'has_barcode',
    ];

    /** Sales that count as sold (a voided or fully refunded sale did not move stock). */
    private const SOLD_POS = ['completed', 'partial_refund'];
    private const SOLD_ONLINE = ['paid', 'shipped', 'delivered'];

    /** A token that changes whenever anything shown on the list changes. */
    public function version(Vendor $vendor, ?int $storeId): string
    {
        $products = $this->products($vendor, $storeId);

        $parts = [
            (clone $products)->count(),
            (clone $products)->max('updated_at'),
            DB::table('product_store_stock')
                ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
                ->whereIn('product_id', (clone $products)->select('id'))
                ->max('updated_at'),
            DB::table('media')
                ->where('model_type', (new Product)->getMorphClass())
                ->whereIn('model_id', (clone $products)->select('id'))
                ->max('updated_at'),
            DB::table('pos_sales')->where('vendor_id', $vendor->id)
                ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
                ->max('id'),
            DB::table('order_items')->where('vendor_id', $vendor->id)->max('id'),
            now()->format('Y-m-d'), // "sold this week" and "not sold in 30 days" move with the date
        ];

        return substr(sha1(json_encode($parts)), 0, 16);
    }

    /**
     * Everything the page needs: the rows, the categories they use, and the
     * version they were built at.
     *
     * @return array{v: string, columns: array<int, string>, categories: array<int, string>, rows: array<int, array<int, mixed>>, built_at: string}
     */
    public function build(Vendor $vendor, ?int $storeId, bool $withCost): array
    {
        $version = $this->version($vendor, $storeId);
        $key = "product-catalogue:{$vendor->id}:" . ($storeId ?? 'all') . ':' . ($withCost ? 'cost' : 'nocost') . ":{$version}";

        return Cache::remember($key, now()->addHours(6), fn () => $this->fresh($vendor, $storeId, $withCost) + ['v' => $version]);
    }

    /**
     * The same rows for a given list of products (the first page, rendered
     * with the page so it shows before the full list arrives).
     *
     * @param  Collection<int, Product>  $products  each with store_quantity / store_reserved selected
     * @return array<int, array<int, mixed>>
     */
    public function rowsFor(Collection $products, ?int $storeId, bool $withCost): array
    {
        if ($products->isEmpty()) {
            return [];
        }

        $ids = $products->pluck('id')->all();

        return $this->rows(
            $products,
            $this->photos($ids),
            $this->barcodes($ids),
            $this->sales($products->first()->vendor_id, $storeId, $ids),
            $withCost,
        );
    }

    private function fresh(Vendor $vendor, ?int $storeId, bool $withCost): array
    {
        $products = $this->products($vendor, $storeId)
            ->select([
                'products.id', 'products.vendor_id', 'products.name', 'products.sku', 'products.barcode', 'products.brand',
                'products.search_keywords', 'products.category_id', 'products.status', 'products.unpublish_at',
                'products.price', 'products.cost_price', 'products.low_stock_threshold', 'products.created_at',
                'products.stock_quantity', 'products.reserved_stock',
            ])
            ->when($storeId, fn ($q) => $q->addSelect([
                'store_quantity' => DB::table('product_store_stock')->select('quantity')
                    ->whereColumn('product_id', 'products.id')->where('store_id', $storeId)->limit(1),
                'store_reserved' => DB::table('product_store_stock')->select('reserved')
                    ->whereColumn('product_id', 'products.id')->where('store_id', $storeId)->limit(1),
            ]))
            ->orderByDesc('products.id')
            ->get();

        $ids = $products->pluck('id')->all();

        return [
            'columns'    => self::COLUMNS,
            'categories' => DB::table('categories')
                ->whereIn('id', $products->pluck('category_id')->filter()->unique()->values())
                ->orderBy('name')
                ->pluck('name', 'id')
                ->all(),
            'rows'       => $this->rows($products, $this->photos($ids), $this->barcodes($ids), $this->sales($vendor->id, $storeId, $ids), $withCost),
            'built_at'   => now()->toIso8601ZuluString(),
        ];
    }

    /** The products this list is about: the shop's, at the branch when there is one. */
    private function products(Vendor $vendor, ?int $storeId)
    {
        return Product::query()
            ->where('products.vendor_id', $vendor->id)
            ->when($storeId, fn ($q) => $q->where('products.store_id', $storeId));
    }

    private function rows(Collection $products, array $photos, array $barcodes, array $sales, bool $withCost): array
    {
        $rows = [];

        foreach ($products as $p) {
            $quantity = (float) ($p->store_quantity ?? $p->stock_quantity ?? 0);
            $reserved = (float) ($p->store_reserved ?? $p->reserved_stock ?? 0);
            $available = max(0, $quantity - $reserved);
            $status = ($p->status === 'published' && $p->unpublish_at && Carbon::parse($p->unpublish_at)->isPast()) ? 'expired' : $p->status;
            $extra = $barcodes[$p->id] ?? [];
            $sold = $sales[$p->id] ?? [null, 0];

            $rows[] = [
                $p->id,
                $p->name,
                $p->sku,
                $p->barcode,
                $p->category_id,
                $p->brand,
                $status,
                (float) $p->price,
                $withCost && $p->cost_price !== null ? (float) $p->cost_price : null,
                $this->number($available),
                $this->number($quantity),
                (int) $p->low_stock_threshold,
                $photos[$p->id] ?? null,
                $p->created_at ? Carbon::parse($p->created_at)->getTimestamp() : null,
                $sold[0],
                $this->number($sold[1]),
                // What a search matches: name, SKU, brand, search words and every barcode.
                mb_strtolower(implode(' ', array_unique(array_filter([$p->name, $p->sku, $p->brand, $p->search_keywords, $p->barcode, ...$extra])))),
                filled($p->barcode) || $extra !== [],
            ];
        }

        return $rows;
    }

    /** @return array<int, string> product id => first photo's thumbnail */
    private function photos(array $ids): array
    {
        $photos = [];

        foreach (array_chunk($ids, 1000) as $chunk) {
            Media::query()
                ->where('model_type', (new Product)->getMorphClass())
                ->whereIn('model_id', $chunk)
                ->where('collection_name', 'product-images')
                ->orderBy('order_column')
                ->get()
                ->unique('model_id')
                ->each(function (Media $media) use (&$photos) {
                    $photos[$media->model_id] = $media->hasGeneratedConversion('thumb') ? $media->getUrl('thumb') : $media->getUrl();
                });
        }

        return $photos;
    }

    /** @return array<int, array<int, string>> product id => its extra barcodes (units, cartons) */
    private function barcodes(array $ids): array
    {
        $codes = [];

        foreach (array_chunk($ids, 1000) as $chunk) {
            foreach (DB::table('product_barcodes')->whereIn('product_id', $chunk)->get(['product_id', 'barcode']) as $row) {
                $codes[$row->product_id][] = $row->barcode;
            }
        }

        return $codes;
    }

    /**
     * When each product last sold, and how many in the last 7 days: the till
     * at this branch, and paid online orders.
     *
     * @return array<int, array{0: int|null, 1: float}> product id => [last sold (unix), sold in 7 days]
     */
    private function sales(int $vendorId, ?int $storeId, array $ids): array
    {
        $weekAgo = now()->subDays(7);
        $out = [];

        $add = function ($rows) use (&$out) {
            foreach ($rows as $row) {
                $last = $row->last ? Carbon::parse($row->last)->getTimestamp() : null;
                [$was, $week] = $out[$row->product_id] ?? [null, 0];
                $out[$row->product_id] = [max($was ?? 0, $last ?? 0) ?: null, $week + (float) $row->week];
            }
        };

        foreach (array_chunk($ids, 1000) as $chunk) {
            $add(DB::table('pos_sale_items')
                ->join('pos_sales', 'pos_sales.id', '=', 'pos_sale_items.pos_sale_id')
                ->where('pos_sales.vendor_id', $vendorId)
                ->when($storeId, fn ($q) => $q->where('pos_sales.store_id', $storeId))
                ->whereIn('pos_sales.status', self::SOLD_POS)
                ->whereIn('pos_sale_items.product_id', $chunk)
                ->groupBy('pos_sale_items.product_id')
                ->selectRaw('pos_sale_items.product_id, max(pos_sales.completed_at) as last, sum(case when pos_sales.completed_at >= ? then pos_sale_items.quantity else 0 end) as week', [$weekAgo])
                ->get());

            $add(DB::table('order_items')
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->where('order_items.vendor_id', $vendorId)
                ->whereIn('orders.status', self::SOLD_ONLINE)
                ->whereIn('order_items.product_id', $chunk)
                ->groupBy('order_items.product_id')
                ->selectRaw('order_items.product_id, max(orders.created_at) as last, sum(case when orders.created_at >= ? then order_items.quantity else 0 end) as week', [$weekAgo])
                ->get());
        }

        return $out;
    }

    /** 12 rather than 12.0: whole numbers stay whole, halves stay halves. */
    private function number(float $value): int|float
    {
        return floor($value) == $value ? (int) $value : round($value, 3);
    }
}
