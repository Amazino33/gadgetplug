<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\ProductStoreStock;
use App\Models\Store;
use Illuminate\Support\Collection;

// Decides which of a vendor's stores supply a line, and how many units each.
//
// The storefront advertises one combined number across the vendor's branches,
// so checkout has to be able to honour it. Before this, the guard asked only
// the default store, and stock sitting in a second branch was visible to the
// customer and unreachable at checkout.
//
// The rule is fixed, not configurable, and deliberately boring so the same
// basket always allocates the same way:
//   1. If one store alone can cover the line, use one store — the default if
//      it can, otherwise the one holding the most. Fewest splits wins, because
//      every split is another shelf someone has to walk to.
//   2. Otherwise take from the default store first, then from the rest by
//      descending available stock, until the line is filled.
// Ties break on store id so the outcome never depends on row order.
//
// Availability is quantity − reserved on each store's row, never the product
// mirror: the mirror is the vendor-wide sum and would happily "cover" a line
// no single branch can actually fill.
class StoreAllocator
{
    /**
     * @return array<int, int> store id => units, in the order they should be taken
     */
    public static function allocate(int $vendorId, int $productId, int $quantity): array
    {
        if ($quantity < 1) {
            return [];
        }

        $candidates = self::availableByStore($vendorId, $productId);

        if ($candidates->sum('available') < $quantity) {
            return [];
        }

        // 1. One store, if one store can do it.
        $single = $candidates->firstWhere(fn ($row) => $row['is_default'] && $row['available'] >= $quantity)
            ?? $candidates->first(fn ($row) => $row['available'] >= $quantity);

        if ($single) {
            return [$single['store_id'] => $quantity];
        }

        // 2. Otherwise spread, default first then biggest holdings.
        $allocation = [];
        $remaining = $quantity;

        foreach ($candidates as $row) {
            if ($remaining < 1) {
                break;
            }

            if ($row['available'] < 1) {
                continue;
            }

            $take = min($remaining, $row['available']);
            $allocation[$row['store_id']] = $take;
            $remaining -= $take;
        }

        // Belt and braces: the sum check above already guarantees this, and a
        // silent short allocation would under-reserve and oversell.
        return $remaining === 0 ? $allocation : [];
    }

    /**
     * Combined available units across the vendor's stores — the number the
     * storefront's mirror implies, computed the way checkout will honour it.
     */
    public static function combinedAvailable(int $vendorId, int $productId): int
    {
        return (int) self::availableByStore($vendorId, $productId)->sum('available');
    }

    /**
     * @return Collection<int, array{store_id: int, available: int, is_default: bool}>
     */
    private static function availableByStore(int $vendorId, int $productId): Collection
    {
        return ProductStoreStock::query()
            ->join('stores', 'stores.id', '=', 'product_store_stock.store_id')
            ->where('stores.vendor_id', $vendorId)
            ->where('product_store_stock.product_id', $productId)
            // An inactive branch is not somewhere an order can be filled from;
            // its stock stays counted in the mirror but is not sellable.
            ->where('stores.is_active', true)
            ->orderByDesc('stores.is_default')
            ->orderByRaw('(product_store_stock.quantity - product_store_stock.reserved) DESC')
            ->orderBy('stores.id')
            ->get([
                'product_store_stock.store_id',
                'product_store_stock.quantity',
                'product_store_stock.reserved',
                'stores.is_default',
            ])
            ->map(fn ($row) => [
                'store_id'   => (int) $row->store_id,
                'available'  => max(0, (int) $row->quantity - (int) $row->reserved),
                'is_default' => (bool) $row->is_default,
            ])
            ->values();
    }
}
