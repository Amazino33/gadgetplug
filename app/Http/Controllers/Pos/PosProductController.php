<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PosProductController extends Controller
{
    // Full catalogue for IndexedDB seed (called once on login)
    public function index(Request $request): JsonResponse
    {
        $request->validate(['vendor_id' => 'required|integer']);

        $products = Product::published()
            ->where('vendor_id', $request->vendor_id)
            ->whereRaw('(stock_quantity - reserved_stock) > 0')
            ->with('media')
            ->select(['id', 'name', 'sku', 'barcode', 'price', 'stock_quantity', 'reserved_stock', 'vendor_id'])
            ->get()
            ->map(fn ($p) => $this->format($p));

        return response()->json($products);
    }

    // Live search by barcode, SKU, or name
    public function search(Request $request): JsonResponse
    {
        $request->validate([
            'vendor_id' => 'required|integer',
            'q'         => 'required|string|min:1',
        ]);

        $q = $request->q;

        $products = Product::published()
            ->where('vendor_id', $request->vendor_id)
            ->where(fn ($query) => $query
                ->where('barcode', $q)
                ->orWhere('sku', $q)
                ->orWhere('name', 'like', "%{$q}%")
            )
            ->with('media')
            ->select(['id', 'name', 'sku', 'barcode', 'price', 'stock_quantity', 'reserved_stock', 'vendor_id'])
            ->limit(20)
            ->get()
            ->map(fn ($p) => $this->format($p));

        return response()->json($products);
    }

    private function format(Product $p): array
    {
        return [
            'id'              => $p->id,
            'name'            => $p->name,
            'sku'             => $p->sku,
            'barcode'         => $p->barcode,
            'price'           => (float) $p->price,
            'available_stock' => $p->available_stock,
            'image'           => $p->getFirstMediaUrl('product-images', 'thumb'),
        ];
    }
}
