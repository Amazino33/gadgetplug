<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductStoreStock;
use App\Models\Vendor;
use App\Services\Inventory\TillStore;
use App\Services\Pos\PosPriceFloor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PosProductController extends Controller
{
    public function __construct(private PosPriceFloor $priceFloor) {}

    // Full catalogue for IndexedDB seed (called once on login).
    //
    // Scoped to the cashier's branch at the SOURCE, not on the screen. This
    // payload is written straight into IndexedDB on whatever device the
    // cashier is holding and is only refreshed at the next login, so anything
    // returned here can be seen — and attempted — offline for days. Filtering
    // in the client would leave another branch's catalogue sitting on the
    // device; filtering here means it never arrives.
    public function index(Request $request): JsonResponse
    {
        $request->validate(['vendor_id' => 'required|integer']);

        $vendor = Vendor::findOrFail($request->vendor_id);

        $products = $this->branchCatalogue($request)
            ->with('media')
            ->get()
            ->map(fn ($p) => $this->format($p, $vendor));

        return response()->json($products);
    }

    // Live search by barcode, SKU, or name
    public function search(Request $request): JsonResponse
    {
        $request->validate([
            'vendor_id' => 'required|integer',
            'q'         => 'required|string|min:1',
        ]);

        $vendor = Vendor::findOrFail($request->vendor_id);
        $q      = $request->q;

        // Scoped identically to index(). Search matters just as much: it is
        // the till's fallback when a barcode misses the local cache, so an
        // unscoped search would hand another branch's product straight to a
        // cashier who could then try to sell it.
        $products = $this->branchCatalogue($request)
            ->where(fn ($query) => $query
                ->where('barcode', $q)
                ->orWhere('sku', $q)
                ->orWhere('name', 'like', "%{$q}%")
            )
            ->with('media')
            ->limit(20)
            ->get()
            ->map(fn ($p) => $this->format($p, $vendor));

        return response()->json($products);
    }

    /**
     * The products this till may sell: homed at the cashier's branch, and
     * holding stock there.
     *
     * The branch comes from TillStore::resolve() — the same resolution the
     * sale path has used since Phase 4, so what the till displays and what it
     * decrements can never disagree about which shop it is standing in.
     *
     * Availability is read from that branch's product_store_stock row rather
     * than the products mirror. Under one-home-store the two are equal, but
     * the row is the figure AdjustStockAction actually guards on, and reading
     * the same number the server will enforce is what keeps the display honest.
     */
    private function branchCatalogue(Request $request)
    {
        $storeId = TillStore::resolve($request->user(), (int) $request->vendor_id);

        return Product::visibleInPos()
            ->where('products.vendor_id', $request->vendor_id)
            ->where('products.store_id', $storeId)
            ->whereExists(fn ($q) => $q
                ->selectRaw('1')
                ->from('product_store_stock')
                ->whereColumn('product_store_stock.product_id', 'products.id')
                ->where('product_store_stock.store_id', $storeId)
                ->whereRaw('product_store_stock.quantity - product_store_stock.reserved > 0'))
            ->select($this->columns())
            ->addSelect([
                'store_quantity' => ProductStoreStock::select('quantity')
                    ->whereColumn('product_id', 'products.id')
                    ->where('store_id', $storeId)
                    ->limit(1),
                'store_reserved' => ProductStoreStock::select('reserved')
                    ->whereColumn('product_id', 'products.id')
                    ->where('store_id', $storeId)
                    ->limit(1),
            ]);
    }

    // cost_price and allow_pos_price_override are selected only to work out the
    // floor below — neither is ever returned. What a product cost the store is
    // nobody's business at the till, and this payload is cached in IndexedDB on
    // whatever device the cashier happens to be holding.
    private function columns(): array
    {
        return [
            'products.id', 'products.name', 'products.sku', 'products.barcode',
            'products.price', 'products.cost_price',
            'products.allow_pos_price_override', 'products.stock_quantity',
            'products.reserved_stock', 'products.low_stock_threshold',
            'products.vendor_id', 'products.store_id',
        ];
    }

    private function format(Product $p, Vendor $vendor): array
    {
        $minPrice = $this->priceFloor->floorFor($p, (float) ($vendor->pos_min_margin_percent ?? 0));

        return [
            'id'              => $p->id,
            'name'            => $p->name,
            'sku'             => $p->sku,
            'barcode'         => $p->barcode,
            'price'           => (float) $p->price,
            'min_price'       => $minPrice,
            // False when the product is locked, has no recorded cost, or is
            // already priced at its floor — in every case there's nothing to
            // haggle over, so the till shouldn't offer the option.
            'can_negotiate'   => $minPrice < (float) $p->price,
            // The branch's own figures — storeAvailable()/isStoreLowStock()
            // read the store_quantity/store_reserved selected above and fall
            // back to the mirror when they are absent.
            'available_stock' => $p->storeAvailable(),
            'is_low_stock'    => $p->isStoreLowStock(),
            'image'           => $p->getFirstMediaUrl('product-images', 'thumb'),
        ];
    }
}
