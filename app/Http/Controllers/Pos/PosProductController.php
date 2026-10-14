<?php

namespace App\Http\Controllers\Pos;

use App\Support\Quantity;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductStoreStock;
use App\Models\ProductUnit;
use App\Services\Inventory\NegativeStock;
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
        $request->validate([
            'vendor_id' => 'required|integer',
            // Paging. A catalogue of thousands built in one response ran past
            // shared hosting's memory limit (8,000 products peaked near 180MB),
            // the request died, and the till silently kept whatever copy it
            // had — so only products a cashier had searched for would scan.
            // The till now asks for a page at a time, in id order.
            'after_id'  => 'nullable|integer|min:0',
            'limit'     => 'nullable|integer|min:1|max:1000',
        ]);

        $vendor = Vendor::findOrFail($request->vendor_id);

        $products = $this->branchCatalogue($request)
            ->with(['media', 'units', 'barcodes'])
            // Without a limit: the whole catalogue, exactly as before, for a
            // till still running the older code.
            ->when($request->filled('limit'), fn ($q) => $q
                ->where('products.id', '>', (int) $request->input('after_id', 0))
                ->orderBy('products.id')
                ->limit((int) $request->input('limit')))
            ->get()
            ->map(fn ($p) => $this->format($p, $vendor));

        return response()->json($products);
    }

    /**
     * Why a scanned code is not on sale at this till, in words a cashier can
     * pass on.
     *
     * Asked only after a scan found nothing. "No product for barcode" was all a
     * till could say, whether the code is unknown or belongs to a product that
     * is a draft, hidden from the Sales Point, at another branch or out of
     * stock — four problems with four different fixes, all looking the same.
     */
    public function whyNotSellable(Request $request): JsonResponse
    {
        $request->validate([
            'vendor_id' => 'required|integer',
            'code'      => 'required|string|max:100',
        ]);

        $code = trim($request->code);

        $product = Product::where('vendor_id', $request->vendor_id)
            ->where(fn ($q) => $q
                ->where('barcode', $code)
                ->orWhere('sku', $code)
                ->orWhereIn('id', ProductBarcode::select('product_id')
                    ->where('vendor_id', $request->vendor_id)
                    ->where('barcode', $code))
                // A unit's own SKU (ALC-CASTLI-0042-CR12) opens that unit.
                ->orWhereIn('id', ProductUnit::select('product_id')
                    ->where('vendor_id', $request->vendor_id)
                    ->where('sku', $code)))
            ->first();

        if ($product === null) {
            return response()->json(['found' => false]);
        }

        $storeId = TillStore::resolve($request->user(), (int) $request->vendor_id);
        $vendor  = Vendor::find($request->vendor_id);

        $live = Product::whereKey($product->id)->published()->exists();
        $row  = ProductStoreStock::where('product_id', $product->id)->where('store_id', $storeId)->first();
        $onShelf = $row ? Quantity::sub($row->quantity, $row->reserved) : 0;

        $reason = match (true) {
            ! $live                       => 'it is not published (it is a draft, archived or scheduled). Set its Status to Published.',
            ! $product->show_in_pos       => '"Show in POS" is turned off on the product.',
            (int) $product->store_id !== $storeId
                                          => 'it belongs to another branch.',
            $onShelf <= 0 && ! NegativeStock::allowed($product, $vendor)
                                          => 'it has no stock at this branch. Add stock with a count, a procurement or Stock Adjustment — or allow selling below zero.',
            default                       => null,
        };

        return response()->json([
            'found'  => true,
            'name'   => $product->name,
            'reason' => $reason,
        ]);
    }

    /**
     * Makes a unit the one this product goes into the cart as.
     *
     * Any cashier may: it changes how the till behaves, not what anything
     * costs. Scoped like every other call here — the product and the unit
     * must both belong to the vendor the till is signed in to.
     */
    public function setDefaultUnit(Request $request, int $productId): JsonResponse
    {
        $request->validate([
            'vendor_id' => 'required|integer',
            'unit_id'   => 'required|integer',
        ]);

        $product = Product::where('vendor_id', $request->vendor_id)->findOrFail($productId);

        $unit = ProductUnit::where('product_id', $product->id)
            ->where('is_active', true)
            ->findOrFail($request->unit_id);

        $product->update(['default_unit_id' => $unit->id]);

        return response()->json(['default_unit_id' => $unit->id]);
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
                // A second barcode, or a unit's own (a crate's code).
                ->orWhereIn('products.id', ProductBarcode::select('product_id')
                    ->where('vendor_id', $request->vendor_id)
                    ->where('barcode', $q))
                // A unit's own SKU, e.g. ALC-CASTLI-0042-CR12.
                ->orWhereIn('products.id', ProductUnit::select('product_id')
                    ->where('vendor_id', $request->vendor_id)
                    ->where('sku', $q))
            )
            ->with(['media', 'units', 'barcodes'])
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

        // A product the shop sells below zero stays on the till with nothing
        // on the shelf — that is the whole point of allowing it.
        $shopAllowsNegative = (bool) Vendor::whereKey($request->vendor_id)->value('allow_negative_stock');

        return Product::visibleInPos()
            ->where('products.vendor_id', $request->vendor_id)
            ->where('products.store_id', $storeId)
            ->where(fn ($stocked) => $stocked
                ->whereExists(fn ($q) => $q
                    ->selectRaw('1')
                    ->from('product_store_stock')
                    ->whereColumn('product_store_stock.product_id', 'products.id')
                    ->where('product_store_stock.store_id', $storeId)
                    ->whereRaw('product_store_stock.quantity - product_store_stock.reserved > 0'))
                ->orWhere('products.negative_stock_override', true)
                ->when($shopAllowsNegative, fn ($q) => $q->orWhereNull('products.negative_stock_override')))
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
            'products.base_unit', 'products.wholesale_price',
            'products.default_unit_id', 'products.negative_stock_override',
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
            // Shown to the cashier so an override of an online reservation is
            // a deliberate choice, not a blind one — the goods are still
            // physically here (available_stock already accounts for this),
            // but someone is counting on them.
            'reserved'        => $p->storeReserved(),
            'image'           => $p->getFirstMediaUrl('product-images', 'thumb'),

            // ── Selling units ──
            // Signed, unlike available_stock: a product sold below zero shows
            // what is owed rather than a flat 0.
            'on_hand'         => $p->storeQuantity(),
            'base_unit'       => $p->base_unit ?: 'Piece',
            'default_unit_id' => $p->default_unit_id,
            'allow_negative'  => NegativeStock::allowed($p, $vendor),
            'units'           => $this->formatUnits($p, $vendor),
            // Every code this product answers to; unit_id null = base unit.
            // A bigger unit's SKU is listed with its barcodes: the till already
            // opens the right unit for any code in this list, and a SKU typed
            // or scanned is just another code for it.
            'barcodes'        => $p->relationLoaded('barcodes')
                ? $p->barcodes->map(fn ($b) => ['barcode' => $b->barcode, 'unit_id' => $b->product_unit_id])
                    ->concat($p->relationLoaded('units')
                        ? $p->units->filter(fn ($u) => ! $u->is_base && filled($u->sku))
                            ->map(fn ($u) => ['barcode' => $u->sku, 'unit_id' => $u->id])
                        : [])
                    ->values()
                : [],
        ];
    }

    /**
     * The units the till may sell this product in, each with its own floors.
     *
     * Floors are worked out here, as min_price always was, so what it costs
     * the shop never reaches the device — only how low a price may go.
     */
    private function formatUnits(Product $p, Vendor $vendor): array
    {
        if (! $p->relationLoaded('units')) {
            return [];
        }

        $margin = (float) ($vendor->pos_min_margin_percent ?? 0);

        return $p->units->where('is_active', true)->map(function (ProductUnit $u) use ($p, $margin) {
            // The base unit's retail price is the product's own price, which
            // for a resold listing is quoted live from the supplier.
            $price     = $u->is_base ? (float) $p->price : (float) $u->price;
            $wholesale = $u->is_base
                ? ($p->wholesale_price !== null ? (float) $p->wholesale_price : null)
                : ($u->wholesale_price !== null ? (float) $u->wholesale_price : null);

            return [
                'id'                  => $u->id,
                'name'                => $u->name,
                'conversion_factor'   => (int) $u->conversion_factor,
                'is_base'             => (bool) $u->is_base,
                'price'               => $price,
                'wholesale_price'     => $wholesale,
                'min_price'           => $this->priceFloor->floorForUnit($p, $u, 'retail', $margin),
                'wholesale_min_price' => $wholesale !== null
                    ? $this->priceFloor->floorForUnit($p, $u, 'wholesale', $margin)
                    : null,
                'sort_order'          => (int) $u->sort_order,
            ];
        })->values()->all();
    }
}
