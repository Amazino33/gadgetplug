<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Vendor;
use App\Services\Pos\PosPriceFloor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PosProductController extends Controller
{
    public function __construct(private PosPriceFloor $priceFloor) {}

    // Full catalogue for IndexedDB seed (called once on login)
    public function index(Request $request): JsonResponse
    {
        $request->validate(['vendor_id' => 'required|integer']);

        $vendor = Vendor::findOrFail($request->vendor_id);

        $products = Product::visibleInPos()
            ->where('vendor_id', $request->vendor_id)
            ->whereRaw('CAST(stock_quantity AS SIGNED) - CAST(reserved_stock AS SIGNED) > 0')
            ->with('media')
            ->select($this->columns())
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

        $products = Product::visibleInPos()
            ->where('vendor_id', $request->vendor_id)
            ->where(fn ($query) => $query
                ->where('barcode', $q)
                ->orWhere('sku', $q)
                ->orWhere('name', 'like', "%{$q}%")
            )
            ->with('media')
            ->select($this->columns())
            ->limit(20)
            ->get()
            ->map(fn ($p) => $this->format($p, $vendor));

        return response()->json($products);
    }

    // cost_price and allow_pos_price_override are selected only to work out the
    // floor below — neither is ever returned. What a product cost the store is
    // nobody's business at the till, and this payload is cached in IndexedDB on
    // whatever device the cashier happens to be holding.
    private function columns(): array
    {
        return [
            'id', 'name', 'sku', 'barcode', 'price', 'cost_price',
            'allow_pos_price_override', 'stock_quantity', 'reserved_stock',
            'low_stock_threshold', 'vendor_id',
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
            'available_stock' => $p->available_stock,
            'is_low_stock'    => $p->is_low_stock,
            'image'           => $p->getFirstMediaUrl('product-images', 'thumb'),
        ];
    }
}
